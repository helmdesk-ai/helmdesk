package ai

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	openaiembedding "github.com/cloudwego/eino-ext/components/embedding/openai"
	"github.com/cloudwego/eino-ext/components/model/agenticclaude"
	"github.com/cloudwego/eino-ext/components/model/agenticgemini"
	"github.com/cloudwego/eino-ext/components/model/agenticopenai"
	"github.com/cloudwego/eino/components/embedding"
	"github.com/cloudwego/eino/components/model"
	"github.com/cloudwego/eino/schema"
	"google.golang.org/genai"
)

// runtimeTimeout 是与 LLM/embedding 网络调用共享的上限。
// 给国内模型冷启动留足余量，让缓慢首包仍能被判定为正常响应。
const runtimeTimeout = 25 * time.Second

// RuntimeTimeout 暴露给跨包复用（如 knowledge 模块）。
const RuntimeTimeout = runtimeTimeout

// claudeMaxTokens 是 Claude 运行时调用的输出上限。
// Anthropic 要求必填 max_tokens，其余协议则不设上限走模型默认；这里取一个足够覆盖
// 摘要/润色/打标签等任务的值，避免过小把摘要正文或工具调用 JSON 截断成非法输出。
const claudeMaxTokens = 4096

// ErrUnsupportedProtocol 与 ErrUnsupportedModelType 暴露给跨包复用。
var (
	ErrUnsupportedProtocol  = errUnsupportedProtocol
	ErrUnsupportedModelType = errUnsupportedModelType
)

// checkDeadline 限制一次连通性测试的整体耗时（包含客户端创建、Generate、首包）。
const checkDeadline = 30 * time.Second

var errUnsupportedProtocol = errors.New("runtime bridge does not support this provider protocol yet")
var errUnsupportedModelType = errors.New("runtime bridge does not support this model type yet")

// validate 根据 request.Mode 分派到 provider 或 model 校验；mode 为空时默认走 provider-save。
func validate(ctx context.Context, request BridgeRequest) BridgeResponse {
	if request.Mode == "" {
		request.Mode = "provider-save"
	}

	switch request.Mode {
	case "provider-save":
		return validateProvider(ctx, request.Provider)
	case "model-save":
		if request.CandidateModel == nil {
			return BridgeResponse{
				Success:   false,
				Supported: false,
				Code:      CodeValidateCandidateModelRequired,
				Message:   "candidate model is required",
			}
		}

		return validateModel(ctx, request.Provider, *request.CandidateModel)
	default:
		return BridgeResponse{
			Success:   false,
			Supported: false,
			Code:      CodeValidateUnsupportedMode,
			Params:    map[string]any{"mode": request.Mode},
			Message:   fmt.Sprintf("unsupported validation mode: %s", request.Mode),
		}
	}
}

// check 选取首个 active 的 LLM 并在 checkDeadline 超时内发起一次真实调用，确认凭据/网络/模型可用。
func check(ctx context.Context, request BridgeRequest) BridgeResponse {
	model := firstActiveLLM(request.Provider.Models)
	if model == nil {
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeCheckNoActiveLLM,
			Message:   "no active llm model is available for runtime check",
		}
	}

	if missing := missingBridgeProviderCredentials(request.Provider); len(missing) > 0 {
		joined := strings.Join(missing, ", ")
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeCheckMissingCredentials,
			Params:    map[string]any{"fields": joined},
			Message:   fmt.Sprintf("missing required credentials: %s", joined),
		}
	}

	testCtx, cancel := context.WithTimeout(ctx, checkDeadline)
	defer cancel()

	if err := testModelConnection(testCtx, request.Provider, *model); err != nil {
		return runtimeErrorResponse(err, CodeCheckUnsupported, CodeCheckRuntimeError)
	}

	return BridgeResponse{
		Success:   true,
		Supported: true,
		Code:      CodeCheckSucceeded,
		Message:   "runtime check succeeded",
	}
}

// runtimeErrorResponse 把"协议/模型不支持"与"远端运行时错误"两类失败映射到统一响应结构。
// unsupportedCode 对应 supported=false 的不支持分支，runtimeCode 对应 supported=true 的远端错误分支。
func runtimeErrorResponse(err error, unsupportedCode, runtimeCode string) BridgeResponse {
	if errors.Is(err, errUnsupportedProtocol) || errors.Is(err, errUnsupportedModelType) {
		return BridgeResponse{
			Success:   false,
			Supported: false,
			Code:      unsupportedCode,
			Params:    map[string]any{"reason": err.Error()},
			Message:   err.Error(),
		}
	}

	return BridgeResponse{
		Success:   false,
		Supported: true,
		Code:      runtimeCode,
		Params:    map[string]any{"error": err.Error()},
		Message:   err.Error(),
	}
}

// validateProvider 在 provider-save 场景下校验必填凭据，并尝试用首个 active LLM 实例化客户端，
// 以确认凭据组合在运行时被 SDK 接受。
func validateProvider(ctx context.Context, provider BridgeProvider) BridgeResponse {
	if missing := missingBridgeProviderCredentials(provider); len(missing) > 0 {
		joined := strings.Join(missing, ", ")
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeValidateMissingCredentials,
			Params:    map[string]any{"fields": joined},
			Message:   fmt.Sprintf("missing required credentials: %s", joined),
		}
	}

	model := firstActiveLLM(provider.Models)
	if model == nil {
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeValidateNoActiveModel,
			Message:   "no active llm model is configured; runtime validation cannot be completed",
		}
	}

	if err := validateModelInstantiation(ctx, provider, *model); err != nil {
		return runtimeErrorResponse(err, CodeValidateUnsupported, CodeValidateRuntimeError)
	}

	return BridgeResponse{
		Success:   true,
		Supported: true,
		Code:      CodeValidateProviderAccepted,
		Message:   "provider configuration accepted by runtime",
	}
}

// validateModel 在 model-save 场景下校验指定模型能否被 SDK 实例化；要求 provider 凭据已完整。
func validateModel(ctx context.Context, provider BridgeProvider, model BridgeModel) BridgeResponse {
	if len(missingBridgeProviderCredentials(provider)) > 0 {
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeValidateIncompleteCredential,
			Message:   "provider credentials are incomplete; runtime validation cannot be completed",
		}
	}

	if err := validateModelInstantiation(ctx, provider, model); err != nil {
		return runtimeErrorResponse(err, CodeValidateUnsupported, CodeValidateRuntimeError)
	}

	return BridgeResponse{
		Success:   true,
		Supported: true,
		Code:      CodeValidateModelAccepted,
		Message:   "model configuration accepted by runtime",
	}
}

// firstActiveLLM 返回第一个 type=llm 且 IsActive=true 的模型副本；找不到返回 nil。
func firstActiveLLM(models []BridgeModel) *BridgeModel {
	for _, model := range models {
		if model.Type == "llm" && model.IsActive {
			candidate := model
			return &candidate
		}
	}

	return nil
}

// validateModelInstantiation 按 model.Type（llm/embedding）分派到对应的实例化函数，仅校验能否构造客户端、不发起调用。
func validateModelInstantiation(ctx context.Context, provider BridgeProvider, model BridgeModel) error {
	switch model.Type {
	case "llm":
		return instantiateLLM(ctx, provider, model.ModelID)
	case "embedding":
		return instantiateEmbedding(ctx, provider, model.ModelID)
	default:
		return fmt.Errorf("%w: %s", errUnsupportedModelType, model.Type)
	}
}

// testModelConnection 按 model.Type 分派到 testLLM/testEmbedding，发起一次真实远端调用做连通性测试。
func testModelConnection(ctx context.Context, provider BridgeProvider, model BridgeModel) error {
	switch model.Type {
	case "llm":
		return testLLM(ctx, provider, model.ModelID)
	case "embedding":
		return testEmbedding(ctx, provider, model.ModelID)
	default:
		return fmt.Errorf("%w: %s", errUnsupportedModelType, model.Type)
	}
}

// instantiateLLM 校验能否为该协议构造 agentic model（不发起远端请求）。无 agentic 组件的协议视为不支持对话。
func instantiateLLM(ctx context.Context, provider BridgeProvider, modelID string) error {
	if !supportsAgenticModel(provider.Protocol) {
		return fmt.Errorf("%w: %s", errUnsupportedProtocol, provider.Protocol)
	}
	_, err := buildAgenticModel(ctx, provider, modelID, false)
	return err
}

// instantiateEmbedding 统一走 OpenAI 兼容 embedder（按 base_uri 接入 qwen/ollama 等兼容品牌），不发起请求。
func instantiateEmbedding(ctx context.Context, provider BridgeProvider, modelID string) error {
	if provider.Protocol != "openai" {
		return fmt.Errorf("%w: %s embedding", errUnsupportedProtocol, provider.Protocol)
	}

	_, err := openaiembedding.NewEmbedder(ctx, buildOpenAIEmbeddingConfig(provider, modelID))

	return err
}

// testLLM 构造 agentic model 并发送 "Hi" 探针，校验远端能返回非空响应。无 agentic 组件的协议视为不支持对话。
func testLLM(ctx context.Context, provider BridgeProvider, modelID string) error {
	if !supportsAgenticModel(provider.Protocol) {
		return fmt.Errorf("%w: %s", errUnsupportedProtocol, provider.Protocol)
	}

	chat, err := buildAgenticModel(ctx, provider, modelID, false)
	if err != nil {
		return err
	}
	response, err := chat.Generate(ctx, []*schema.AgenticMessage{schema.UserAgenticMessage("Hi")})
	if err != nil {
		return err
	}
	if response == nil || strings.TrimSpace(response.String()) == "" {
		return errors.New("runtime returned an empty response")
	}
	return nil
}

// testEmbedding 走 OpenAI 兼容 embedder 对 "hello" 跑一次 EmbedStrings，校验远端返回非空向量。
func testEmbedding(ctx context.Context, provider BridgeProvider, modelID string) error {
	if provider.Protocol != "openai" {
		return fmt.Errorf("%w: %s embedding", errUnsupportedProtocol, provider.Protocol)
	}

	return probeEmbedder(ctx, func() (embedding.Embedder, error) {
		return openaiembedding.NewEmbedder(ctx, buildOpenAIEmbeddingConfig(provider, modelID))
	})
}

// probeEmbedder 用工厂构造 Embedder，对 "hello" 跑一次 EmbedStrings 并要求向量列表非空。
// 与 probeBaseChat 一样，工厂签名让 caller 选择具体的 Embedder 实现，共用响应判定逻辑。
func probeEmbedder(ctx context.Context, build func() (embedding.Embedder, error)) error {
	embedder, err := build()
	if err != nil {
		return err
	}
	embeddings, err := embedder.EmbedStrings(ctx, []string{"hello"})
	if err != nil {
		return err
	}
	if len(embeddings) == 0 {
		return errors.New("runtime returned an empty embedding response")
	}
	return nil
}

// officialOpenAIBaseURL 是 OpenAI 官方 API 的 v1 根地址。
const officialOpenAIBaseURL = "https://api.openai.com/v1"

// buildAgenticOpenAIConfig 构造 Chat Completions 客户端配置：有自定义 base_uri 就用
// （deepseek/qwen/ark/ollama 等 OpenAI 兼容品牌），否则回退到 OpenAI 官方根地址。
func buildAgenticOpenAIConfig(provider BridgeProvider, modelID string, disableThinking bool) *agenticopenai.ChatConfig {
	cfg := &agenticopenai.ChatConfig{
		APIKey:  provider.Credentials["key"],
		Model:   modelID,
		Timeout: runtimeTimeout,
	}

	if baseURL := strings.TrimSpace(provider.Credentials["base_uri"]); baseURL != "" {
		cfg.BaseURL = baseURL
	} else {
		cfg.BaseURL = officialOpenAIBaseURL
	}

	if extraFields := disableThinkingExtraFields(provider, disableThinking); len(extraFields) > 0 {
		cfg.ExtraFields = extraFields
	}

	return cfg
}

// disableThinkingByBrand 按品牌登记「关闭思考」需经 ExtraFields 透传的请求体字段。
// 各家参数不同：deepseek/ark/doubao 用 thinking.type=disabled，qwen 用 enable_thinking=false，
// openai 用 reasoning_effort=minimal（gpt-5 起的最低推理档）。
// 新增 OpenAI 兼容品牌只需在此追加一行；gemini 走原生 ThinkingConfig，见 buildAgenticModel 的 gemini 分支。
var disableThinkingByBrand = map[string]map[string]any{
	"deepseek": {"thinking": map[string]any{"type": "disabled"}},
	"ark":      {"thinking": map[string]any{"type": "disabled"}},
	"doubao":   {"thinking": map[string]any{"type": "disabled"}},
	"qwen":     {"enable_thinking": false},
	"openai":   {"reasoning_effort": "minimal"},
}

// disableThinkingExtraFields 取当前 provider 关闭思考所需的额外请求体字段；品牌未登记则返回 nil。
func disableThinkingExtraFields(provider BridgeProvider, disableThinking bool) map[string]any {
	if !disableThinking {
		return nil
	}

	return disableThinkingByBrand[strings.ToLower(strings.TrimSpace(provider.Brand))]
}

// supportsAgenticModel 判断该协议是否有 eino agentic model 组件（即可走 AgenticMessage 通道）。
// 底层只保留三种原生协议；其它品牌（deepseek/qwen/azure/ollama 等）在品牌目录里映射到这三者之一 + 预设 base_url。
func supportsAgenticModel(protocol string) bool {
	switch protocol {
	case "openai", "anthropic", "gemini":
		return true
	default:
		return false
	}
}

// buildAgenticModel 按供应商协议构造 eino agentic model（model.AgenticModel）。
// 这是全应用统一的对话/agent 模型入口：实时对话与 task agent 直接用它走 ADK 泛型 agent；
// 非 agent 任务（打标签/摘要/改写/RAPTOR）经 agenticToolCallingModel 适配成 ToolCallingChatModel 后复用。
//
// 协议已收敛为三种：openai（Chat Completions，含 deepseek/qwen/ark/ollama 等以预设 base_url 接入的兼容品牌）、
// anthropic（Messages，base_uri 非空即用）、gemini。
func buildAgenticModel(ctx context.Context, provider BridgeProvider, modelID string, disableThinking bool) (model.AgenticModel, error) {
	switch provider.Protocol {
	case "openai":
		return agenticopenai.NewChatModel(ctx, buildAgenticOpenAIConfig(provider, modelID, disableThinking))
	case "anthropic":
		cfg := &agenticclaude.Config{
			APIKey:    provider.Credentials["key"],
			Model:     modelID,
			MaxTokens: claudeMaxTokens,
		}
		if baseURL := strings.TrimSpace(provider.Credentials["base_uri"]); baseURL != "" {
			cfg.BaseURL = baseURL
		}
		if extraFields := disableThinkingExtraFields(provider, disableThinking); len(extraFields) > 0 {
			cfg.ExtraFields = extraFields
		}
		return agenticclaude.New(ctx, cfg)
	case "gemini":
		client, err := genai.NewClient(ctx, buildGeminiClientConfig(provider))
		if err != nil {
			return nil, err
		}
		cfg := &agenticgemini.Config{Client: client, Model: modelID}
		// Gemini 3 系（含 3.5 Flash）用 thinking_level 控制思考，最低档 MINIMAL（≈不思考）；
		// 不能与旧版 thinking_budget 同传，否则 400。Gemini 3 无法完全关思考，MINIMAL 已是最低。
		if disableThinking {
			cfg.ThinkingConfig = &genai.ThinkingConfig{ThinkingLevel: genai.ThinkingLevelMinimal}
		}
		return agenticgemini.New(ctx, cfg)
	default:
		return nil, fmt.Errorf("%w: %s 没有 agentic model 组件", errUnsupportedProtocol, provider.Protocol)
	}
}

// buildGeminiClientConfig 构造 google.golang.org/genai 客户端配置，仅传入 API Key。
func buildGeminiClientConfig(provider BridgeProvider) *genai.ClientConfig {
	return &genai.ClientConfig{
		APIKey: provider.Credentials["key"],
	}
}

// buildOpenAIEmbeddingConfig 构造 OpenAI 兼容 Embedder 配置：有自定义 base_uri 就用（qwen/ollama 等兼容品牌），否则走官方根地址。
func buildOpenAIEmbeddingConfig(provider BridgeProvider, modelID string) *openaiembedding.EmbeddingConfig {
	cfg := &openaiembedding.EmbeddingConfig{
		APIKey:  provider.Credentials["key"],
		Model:   modelID,
		Timeout: runtimeTimeout,
	}

	if baseURL := strings.TrimSpace(provider.Credentials["base_uri"]); baseURL != "" {
		cfg.BaseURL = baseURL
	}

	return cfg
}
