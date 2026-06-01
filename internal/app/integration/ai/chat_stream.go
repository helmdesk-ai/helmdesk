package ai

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/cloudwego/eino/adk"
	"github.com/cloudwego/eino/components/model"
	"github.com/cloudwego/eino/components/tool"
	"github.com/cloudwego/eino/compose"
	"github.com/cloudwego/eino/schema"
	"github.com/dunglas/frankenphp"
	"github.com/dunglas/mercure"
	"github.com/gin-gonic/gin"

	aitools "helmdesk/internal/app/integration/ai/tools"
)

// chatStreamTimeout 是一次 AI 对话流的总时长上限。
// 给足 2 分钟，国内大模型冷启动 + 长文输出时常能接近这个值。
const chatStreamTimeout = 2 * time.Minute

// chatStreamTerminalPublishTimeout 是结束事件（error/done）发布时的独立超时。
// 结束事件用独立的 ctx 发出，让前端始终能收到生成结束的信号。
const chatStreamTerminalPublishTimeout = 5 * time.Second

const chatStreamTimeoutMessage = "AI response timed out. Please try again."

// agentMaxIterations 是一次对话中允许 ChatModel 被调用的最大轮数。
// tool 调用 → 模型反思 → 再次 tool 调用 算一轮，给 5 轮足够覆盖 demo 场景，
// 同时让循环失控时配额仍在掌控之内。
const agentMaxIterations = 5

// 当前阶段保持 system prompt 为空：
//   - 业务侧需求是把使用方式留给用户自己决定；
//   - 后续要支持自定义提示词时，由 PHP 侧从 ChatStreamRequest 透传一个 SystemPrompt 字段过来，
//     在这里再 plumb 进 adk.ChatModelAgentConfig.Instruction 即可。

// ChatMessage 是通过 bridge 传入的对话消息。
// 只暴露最基础的 role/content，业务层的富结构（多模态、工具调用等）留待后续扩展。
type ChatMessage struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// ChatStreamRequest 是 PHP → Go 发起一次流式对话的载荷。
//
// Topic 由 PHP 侧生成，结构是 `urn:helmdesk:ai-chat:{workspaceID}:{ULID}`。
//
// 安全模型注意事项：
//   - 当前 Mercure 的订阅端是匿名的，谁拿到 topic 谁就能 EventSource 接收；
//   - PHP 侧用 ULID 作为最后一段，128 bit 随机 + workspace 前缀已经能抵御穷举；
//   - topic 是唯一的“共享密钥”，把它当成密钥处理：日志与前端公开元数据中一律以 topicLogID 摘要形式呈现。
//
// 后续若引入认证，应在订阅时校验 JWT 中的 workspace 身份，再彻底替换这个“凭 topic 即可订阅”的假设。
type ChatStreamRequest struct {
	Topic    string         `json:"topic"`
	Provider BridgeProvider `json:"provider"`
	Model    BridgeModel    `json:"model"`
	Messages []ChatMessage  `json:"messages"`
	// WorkspaceID 与 topic 中的 workspace 段一致，但显式带一份既方便代码内部使用，
	// 也方便 knowledge_search 这类本地工具直接持有自己的 workspace 上下文。
	WorkspaceID string `json:"workspace_id,omitempty"`
	// McpServers 是 PHP 侧根据当前 workspace 下 is_active 的 MCP 服务整理出来的运行时配置，
	// 携带每台服务的认证 / 自定义 header / 已启用工具白名单。Go 侧据此为本轮对话挂载 MCP 工具。
	McpServers []McpServerForChat `json:"mcp_servers,omitempty"`
	// KnowledgeBases 是 PHP 侧整理过的"本对话允许 Agent 检索的知识库"白名单，包含 id/name/description。
	// 仅当列表非空时挂载 knowledge_search 工具，让 LLM 只在有实际知识库时才看到该选项。
	KnowledgeBases []KnowledgeBaseForChat `json:"knowledge_bases,omitempty"`
}

// KnowledgeBaseForChat 描述一次对话中允许 Agent 检索的一个知识库。
//
// 与 PHP 端 CollectActiveKnowledgeBasesAction 输出一一对应：
//   - ID 是 ULID，用于 knowledge_search 工具调用时回传；
//   - Name / Description 仅用于工具描述渲染，让 LLM 自行挑选合适的知识库。
type KnowledgeBaseForChat struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
}

// McpServerForChat 是一台 MCP 服务在一次对话里的运行时上下文。
//
// 与 mcp.BridgeServer 字段一致，额外附带一个 ToolNames 白名单：
//   - 列表非空：仅暴露这些工具（对应 mcp_tools.is_enabled=true 且仍在线的工具）；
//   - 列表为空：表示用户的启用工具集为空，整台 server 跳过远端调用。
type McpServerForChat struct {
	ID             string            `json:"id"`
	Slug           string            `json:"slug"`
	Name           string            `json:"name"`
	Transport      string            `json:"transport"`
	EndpointURL    string            `json:"endpoint_url"`
	Credentials    map[string]string `json:"credentials"`
	Headers        map[string]string `json:"headers"`
	TimeoutSeconds int               `json:"timeout_seconds"`
	ToolNames      []string          `json:"tool_names,omitempty"`
}

type ChatStreamStopRequest struct {
	Topic string `json:"topic"`
}

// ChatStreamEvent 是推送到 Mercure 的 JSON 载荷结构。
//
// Type 当前覆盖五种：
//   - "delta"       ：assistant 文本增量（content 是增量片段）
//   - "tool_call"   ：模型决定调用工具（tool + args，args 是 raw JSON 字符串）
//   - "tool_result" ：工具执行返回（tool + content，content 是工具原样输出）
//   - "done"        ：一轮对话结束
//   - "error"       ：任意环节失败（error 字段携带人类可读摘要）
//
// ToolDisplay 仅在 MCP 工具上设置，承载"<服务名> / <工具原名>"这种人类可读标签；
// 前端优先用它渲染，缺省时回落到 Tool（LLM 看到的 sanitize 名）。
type ChatStreamEvent struct {
	Type        string `json:"type"`
	Content     string `json:"content,omitempty"`
	Error       string `json:"error,omitempty"`
	Tool        string `json:"tool,omitempty"`
	ToolDisplay string `json:"tool_display,omitempty"`
	Args        string `json:"args,omitempty"`
}

type chatStreamRun struct {
	cancel      context.CancelFunc
	workspaceID string
}

// chatStreamDefaultMaxConcurrent 是每个工作区的流式对话并发上限。
const chatStreamDefaultMaxConcurrent = 10

var activeChatStreams = struct {
	sync.Mutex
	byTopic        map[string]*chatStreamRun
	countWorkspace map[string]int
}{
	byTopic:        make(map[string]*chatStreamRun),
	countWorkspace: make(map[string]int),
}

// extractTopicWorkspaceID 从 `urn:helmdesk:ai-chat:{workspace}:{ulid}` 抽出 workspace 段。
// 非该格式的 topic 视为缺少 workspace 标识，返回空串后 caller 会直接拒绝该请求。
func extractTopicWorkspaceID(topic string) string {
	const prefix = "urn:helmdesk:ai-chat:"
	if !strings.HasPrefix(topic, prefix) {
		return ""
	}
	rest := topic[len(prefix):]
	idx := strings.LastIndex(rest, ":")
	if idx <= 0 {
		return ""
	}
	return rest[:idx]
}

// handleChatStream 处理 PHP 转发过来的流式对话请求。
//
// 接收到请求后先同步 ack（202 Accepted），再用独立 goroutine 跑 adk.Runner 并发到 Mercure。
// 这样做有两个好处：
//  1. PHP worker 立刻拿到 topic 即可返回给浏览器，模型推理耗时完全在 Go 侧消化；
//  2. 流结束 / 出错时才向 Mercure 发 done / error，前端据此可靠地关闭 EventSource。
//
// nativeWorkers 可为 nil：此时 knowledge_search 等本地工具会跳过挂载，主聊天流程仍可用。
func handleChatStream(hub *mercure.Hub, nativeWorkers frankenphp.Workers) gin.HandlerFunc {
	return func(c *gin.Context) {
		var req ChatStreamRequest
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusUnprocessableEntity, gin.H{
				"success": false,
				"error":   err.Error(),
			})
			return
		}

		if strings.TrimSpace(req.Topic) == "" {
			c.JSON(http.StatusUnprocessableEntity, gin.H{
				"success": false,
				"error":   "topic is required",
			})
			return
		}

		if strings.TrimSpace(req.Model.ModelID) == "" {
			c.JSON(http.StatusUnprocessableEntity, gin.H{
				"success": false,
				"error":   "model.model_id is required",
			})
			return
		}

		if len(req.Messages) == 0 {
			c.JSON(http.StatusUnprocessableEntity, gin.H{
				"success": false,
				"error":   "messages must not be empty",
			})
			return
		}

		if hub == nil {
			// 这条日志专门暴露 program.go 启动时漏掉 Mercure hub 注入的场景，
			// 让运维一眼看出是注册顺序问题，归因到 wiring 而非上游故障。
			log.Printf("ai chat stream rejected: mercure hub is nil (program wiring issue)")
			c.JSON(http.StatusInternalServerError, gin.H{
				"success": false,
				"error":   "mercure hub is not initialized",
			})
			return
		}

		workspaceID := extractTopicWorkspaceID(req.Topic)
		if workspaceID == "" {
			c.JSON(http.StatusUnprocessableEntity, gin.H{
				"success": false,
				"error":   "topic must include workspace identifier",
			})
			return
		}

		ctx, cancel := context.WithTimeout(context.Background(), chatStreamTimeout)
		cleanup, accepted := registerChatStreamCancel(req.Topic, workspaceID, cancel, chatStreamDefaultMaxConcurrent)
		if !accepted {
			cancel()
			c.JSON(http.StatusTooManyRequests, gin.H{
				"success": false,
				"error":   "too many concurrent AI chat streams for this workspace",
			})
			return
		}

		c.JSON(http.StatusAccepted, gin.H{
			"success": true,
			"topic":   req.Topic,
		})

		go runChatStream(ctx, cancel, cleanup, hub, nativeWorkers, req)
	}
}

// handleChatStreamStop 处理 PHP 发来的“停止当前流式对话”请求。
// 仅按 topic 触发取消，stopped 字段反映是否真的命中并取消了在跑的任务。
func handleChatStreamStop() gin.HandlerFunc {
	return func(c *gin.Context) {
		var req ChatStreamStopRequest
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusUnprocessableEntity, gin.H{
				"success": false,
				"error":   err.Error(),
			})
			return
		}

		if strings.TrimSpace(req.Topic) == "" {
			c.JSON(http.StatusUnprocessableEntity, gin.H{
				"success": false,
				"error":   "topic is required",
			})
			return
		}

		c.JSON(http.StatusOK, gin.H{
			"success": true,
			"stopped": cancelChatStream(req.Topic),
		})
	}
}

// runChatStream 是实际的后台流式任务。它把异常通过 Mercure 推给前端，并维护 ctx / 全局表的生命周期。
func runChatStream(ctx context.Context, cancel context.CancelFunc, cleanup func(), hub *mercure.Hub, nativeWorkers frankenphp.Workers, req ChatStreamRequest) {
	defer cancel()
	defer cleanup()

	// 工具集与协议无关，两条 agent 线（Message / Responses-agentic）共用。
	tools, mcpHandle, err := buildChatStreamTools(ctx, nativeWorkers, req)
	if err != nil {
		failChatStreamBuild(ctx, hub, req.Topic, "build tools", err)
		return
	}
	defer mcpHandle.Close()

	// 所有供应商统一走 agentic model（AgenticMessage 通道）；无 agentic 组件的协议不支持对话。
	if !supportsAgenticModel(req.Provider.Protocol) {
		failChatStreamBuild(ctx, hub, req.Topic, "build model", fmt.Errorf("%w: %s", ErrUnsupportedProtocol, req.Provider.Protocol))
		return
	}
	runAgenticChatStream(ctx, hub, req, tools, mcpHandle.Display)
}

// buildChatStreamTools 构造一次对话用的全部工具（内置 calculator + MCP + 知识库检索），两条 agent 线共用。
// 返回的 mcpHandle 始终非 nil，由调用方负责 Close。
func buildChatStreamTools(ctx context.Context, nativeWorkers frankenphp.Workers, req ChatStreamRequest) ([]tool.BaseTool, *aitools.McpToolsHandle, error) {
	mcpHandle := aitools.BuildMcpTools(ctx, toMcpServerSpecs(req.McpServers))

	tools, err := buildDefaultTools()
	if err != nil {
		return nil, mcpHandle, fmt.Errorf("build default tools: %w", err)
	}
	tools = append(tools, mcpHandle.Tools...)

	if knowledgeTool := buildKnowledgeSearchTool(nativeWorkers, req); knowledgeTool != nil {
		tools = append(tools, knowledgeTool)
	}

	return aitools.WrapToolErrors(tools), mcpHandle, nil
}

// failChatStreamBuild 统一处理构造阶段（模型/工具/agent/消息）失败：主动取消时静默，否则脱敏落盘并推送 error 终态事件。
func failChatStreamBuild(ctx context.Context, hub *mercure.Hub, topic, stage string, err error) {
	if isChatStreamCanceled(ctx) {
		return
	}
	// 日志走脱敏后的错误，让 SDK 在 error 里夹带 Authorization 等 header 的场景仍能安全落盘。
	log.Printf("chat stream %s failed (%s): %s", stage, topicLogID(topic), scrubSensitive(err.Error()))
	publishTerminalEvent(ctx, hub, topic, ChatStreamEvent{
		Type:  "error",
		Error: sanitizeUpstreamError(err),
	})
}

// runAgenticChatStream 跑基于 *schema.AgenticMessage 的对话：
// 构造 model.AgenticModel + ADK 泛型 agent/Runner，复用同一套工具，输出经 consumeAgenticEvents 转回 Mercure 事件。
func runAgenticChatStream(ctx context.Context, hub *mercure.Hub, req ChatStreamRequest, tools []tool.BaseTool, toolDisplay map[string]string) {
	chat, err := buildAgenticModel(ctx, req.Provider, req.Model.ModelID, false)
	if err != nil {
		failChatStreamBuild(ctx, hub, req.Topic, "build model", err)
		return
	}

	agent, err := adk.NewTypedChatModelAgent(ctx, &adk.TypedChatModelAgentConfig[*schema.AgenticMessage]{
		Name:          "helmdesk-assistant",
		Description:   "Workspace-side AI assistant with built-in calculator plus optional MCP tools.",
		Model:         chat,
		MaxIterations: agentMaxIterations,
		ToolsConfig: adk.ToolsConfig{
			ToolsNodeConfig: compose.ToolsNodeConfig{
				Tools: tools,
			},
		},
	})
	if err != nil {
		failChatStreamBuild(ctx, hub, req.Topic, "build agent", err)
		return
	}

	runner := adk.NewTypedRunner(adk.TypedRunnerConfig[*schema.AgenticMessage]{
		Agent:           agent,
		EnableStreaming: true,
	})

	messages, err := toAgenticMessages(req.Messages)
	if err != nil {
		failChatStreamBuild(ctx, hub, req.Topic, "invalid messages", err)
		return
	}

	iter := runner.Run(ctx, messages)
	consumeAgenticEvents(ctx, hub, req.Topic, iter, toolDisplay)
}

// registerChatStreamCancel 把一次流式对话登记到全局表。
// 同一个 topic 重复进入时会替换当前轮次，让“点重发”能立刻生效。
// 如果 workspace 已达 maxStreams 上限，返回 accepted=false。
//
// 协议约束：topic 里包含 workspaceID 段，extractTopicWorkspaceID 始终能抽出。
// 因此 "existing != nil 但 workspaceID 不同" 在协议层面就是不可达的，本函数按此前提编写。
func registerChatStreamCancel(topic, workspaceID string, cancel context.CancelFunc, maxStreams int) (cleanup func(), accepted bool) {
	run := &chatStreamRun{cancel: cancel, workspaceID: workspaceID}

	activeChatStreams.Lock()
	existing := activeChatStreams.byTopic[topic]

	// 同一 topic 重入会维持 workspace 总数，因此只在新 topic 进入时检查 workspace 并发上限。
	if existing == nil {
		if activeChatStreams.countWorkspace[workspaceID] >= maxStreams {
			activeChatStreams.Unlock()
			return nil, false
		}
	}

	if existing != nil {
		existing.cancel()
		activeChatStreams.countWorkspace[existing.workspaceID]--
		if activeChatStreams.countWorkspace[existing.workspaceID] <= 0 {
			delete(activeChatStreams.countWorkspace, existing.workspaceID)
		}
	}
	activeChatStreams.byTopic[topic] = run
	activeChatStreams.countWorkspace[workspaceID]++
	activeChatStreams.Unlock()

	cleanup = func() {
		activeChatStreams.Lock()
		if activeChatStreams.byTopic[topic] == run {
			delete(activeChatStreams.byTopic, topic)
			activeChatStreams.countWorkspace[workspaceID]--
			if activeChatStreams.countWorkspace[workspaceID] <= 0 {
				delete(activeChatStreams.countWorkspace, workspaceID)
			}
		}
		activeChatStreams.Unlock()
	}
	return cleanup, true
}

// cancelChatStream 按 topic 取消正在进行的一次流式对话，并同步维护 workspace 计数。
// 返回 true 表示确实命中并执行了取消；false 表示该 topic 当前没有在跑的流。
func cancelChatStream(topic string) bool {
	activeChatStreams.Lock()
	run := activeChatStreams.byTopic[topic]
	if run != nil {
		delete(activeChatStreams.byTopic, topic)
		activeChatStreams.countWorkspace[run.workspaceID]--
		if activeChatStreams.countWorkspace[run.workspaceID] <= 0 {
			delete(activeChatStreams.countWorkspace, run.workspaceID)
		}
	}
	activeChatStreams.Unlock()

	if run == nil {
		return false
	}

	run.cancel()
	return true
}

// buildDefaultTools 返回当前内置的所有本地工具。
//
// 目前只有 calculator 一个；新增本地工具时在这里 append 即可保留 agent 配置侧的整洁。
func buildDefaultTools() ([]tool.BaseTool, error) {
	calc, err := aitools.NewCalculatorTool()
	if err != nil {
		return nil, fmt.Errorf("build calculator tool: %w", err)
	}
	return []tool.BaseTool{calc}, nil
}

// buildKnowledgeSearchTool 按需挂载知识库检索工具。
//
//   - workers 为 nil（program.go wiring 缺失）或本对话没有任何可访问的知识库时直接返回 nil，
//     LLM 在该轮对话里看到的工具集自然就少了 knowledge_search 这一项；
//   - 否则用 PHP 透传过来的 (workspace_id, knowledge_bases) 构造一个绑定上下文的工具，
//     工具的 InvokableRun 内部会通过 phpbridge.CallNative 调到 PHP 端 KnowledgeSearchBridgeAction。
//
// 工具实例随对话即用即弃：每个 request 独立构造，确保 workspace 上下文按对话边界隔离。
func buildKnowledgeSearchTool(workers frankenphp.Workers, req ChatStreamRequest) tool.BaseTool {
	if workers == nil {
		return nil
	}
	workspaceID := strings.TrimSpace(req.WorkspaceID)
	if workspaceID == "" {
		workspaceID = extractTopicWorkspaceID(req.Topic)
	}
	if workspaceID == "" || len(req.KnowledgeBases) == 0 {
		return nil
	}

	specs := make([]aitools.KnowledgeBaseSpec, 0, len(req.KnowledgeBases))
	for _, kb := range req.KnowledgeBases {
		specs = append(specs, aitools.KnowledgeBaseSpec{
			ID:          kb.ID,
			Name:        kb.Name,
			Description: kb.Description,
		})
	}

	t, err := aitools.NewKnowledgeSearchTool(workspaceID, specs, workers)
	if err != nil {
		log.Printf("ai chat stream: build knowledge_search tool failed: %s", scrubSensitive(err.Error()))
		return nil
	}
	return t
}

// toMcpServerSpecs 把 PHP 推下来的 MCP 配置投影成 tools 包消费的轻量 spec。
// 仅做字段透传，由 BuildMcpTools 内部对空 endpoint / 空工具列表做整台 server 的跳过处理。
func toMcpServerSpecs(servers []McpServerForChat) []aitools.McpServerSpec {
	if len(servers) == 0 {
		return nil
	}
	out := make([]aitools.McpServerSpec, 0, len(servers))
	for _, s := range servers {
		out = append(out, aitools.McpServerSpec{
			ID:             s.ID,
			Slug:           s.Slug,
			Name:           s.Name,
			Transport:      s.Transport,
			EndpointURL:    s.EndpointURL,
			Credentials:    s.Credentials,
			Headers:        s.Headers,
			TimeoutSeconds: s.TimeoutSeconds,
			ToolNames:      s.ToolNames,
		})
	}
	return out
}

// isChatStreamCanceled 判断当前 ctx 是否因为主动取消（区别于超时）而终止。
// 用于在用户/上游主动停止时跳过错误事件发布，让前端只收到一次终态事件。
func isChatStreamCanceled(ctx context.Context) bool {
	return errors.Is(ctx.Err(), context.Canceled)
}

// consumeAgenticEvents 消费 AgenticMessage 通道的 agent 事件并翻译为 Mercure ChatStreamEvent 流：
// Err → error 终态；Interrupted → 暂不支持的 error；MessageOutput → 拆成 delta/tool_call/tool_result。
func consumeAgenticEvents(
	ctx context.Context,
	hub *mercure.Hub,
	topic string,
	iter *adk.AsyncIterator[*adk.TypedAgentEvent[*schema.AgenticMessage]],
	toolDisplay map[string]string,
) {
	terminalErrorSent := false

	defer func() {
		if isChatStreamCanceled(ctx) {
			return
		}

		if errors.Is(ctx.Err(), context.DeadlineExceeded) && !terminalErrorSent {
			publishTerminalEvent(ctx, hub, topic, ChatStreamEvent{
				Type:  "error",
				Error: chatStreamTimeoutMessage,
			})
		}

		publishTerminalEvent(ctx, hub, topic, ChatStreamEvent{Type: "done"})
	}()

	for {
		event, ok := iter.Next()
		if !ok {
			return
		}

		if event.Err != nil {
			if isChatStreamCanceled(ctx) {
				return
			}
			log.Printf("chat stream agent error (%s): %s", topicLogID(topic), scrubSensitive(event.Err.Error()))
			publishTerminalEvent(ctx, hub, topic, ChatStreamEvent{
				Type:  "error",
				Error: sanitizeUpstreamError(event.Err),
			})
			terminalErrorSent = true
			return
		}

		if event.Action != nil && event.Action.Interrupted != nil {
			if isChatStreamCanceled(ctx) {
				return
			}
			publishTerminalEvent(ctx, hub, topic, ChatStreamEvent{
				Type:  "error",
				Error: "agent requested human approval, which is not supported yet",
			})
			terminalErrorSent = true
			return
		}

		if event.Output == nil || event.Output.MessageOutput == nil {
			if event.Action != nil && event.Action.Exit {
				return
			}
			continue
		}

		if dispatchAgenticOutput(ctx, hub, topic, event.Output.MessageOutput, toolDisplay) {
			terminalErrorSent = true
			return
		}

		if event.Action != nil && event.Action.Exit {
			return
		}
	}
}

// dispatchAgenticOutput 把一条 AgenticMessage 输出（流式或非流式）按内容块类型拆成 Mercure 事件。
// AssistantGenText → delta（流式按 chunk 增量推送）；FunctionToolCall → tool_call（流式按 CallID 聚合 args）；
// FunctionToolResult → tool_result。
func dispatchAgenticOutput(ctx context.Context, hub *mercure.Hub, topic string, mo *adk.TypedMessageVariant[*schema.AgenticMessage], toolDisplay map[string]string) bool {
	if mo.IsStreaming {
		defer mo.MessageStream.Close()

		type toolCallAcc struct {
			name string
			args strings.Builder
		}
		ordered := make([]string, 0, 2)
		byCall := make(map[string]*toolCallAcc)

		for {
			chunk, err := mo.MessageStream.Recv()
			if errors.Is(err, io.EOF) {
				break
			}
			if err != nil {
				if isChatStreamCanceled(ctx) {
					return true
				}
				log.Printf("chat stream assistant recv failed (%s): %s", topicLogID(topic), scrubSensitive(err.Error()))
				publishTerminalEvent(ctx, hub, topic, ChatStreamEvent{
					Type:  "error",
					Error: sanitizeUpstreamError(err),
				})
				return true
			}

			for _, block := range chunk.ContentBlocks {
				switch block.Type {
				case schema.ContentBlockTypeAssistantGenText:
					if block.AssistantGenText == nil || block.AssistantGenText.Text == "" {
						continue
					}
					if err := publishEvent(ctx, hub, topic, ChatStreamEvent{
						Type:    "delta",
						Content: block.AssistantGenText.Text,
					}); err != nil {
						log.Printf("chat stream publish delta failed (%s): %v", topicLogID(topic), err)
						return true
					}
				case schema.ContentBlockTypeFunctionToolCall:
					if block.FunctionToolCall == nil {
						continue
					}
					acc, exists := byCall[block.FunctionToolCall.CallID]
					if !exists {
						acc = &toolCallAcc{}
						byCall[block.FunctionToolCall.CallID] = acc
						ordered = append(ordered, block.FunctionToolCall.CallID)
					}
					if block.FunctionToolCall.Name != "" && acc.name == "" {
						acc.name = block.FunctionToolCall.Name
					}
					acc.args.WriteString(block.FunctionToolCall.Arguments)
				case schema.ContentBlockTypeFunctionToolResult:
					if publishAgenticToolResult(ctx, hub, topic, block.FunctionToolResult, toolDisplay) {
						return true
					}
				}
			}
		}

		for _, callID := range ordered {
			acc := byCall[callID]
			if acc.name == "" {
				continue
			}
			if err := publishEvent(ctx, hub, topic, ChatStreamEvent{
				Type:        "tool_call",
				Tool:        acc.name,
				ToolDisplay: toolDisplay[acc.name],
				Args:        acc.args.String(),
			}); err != nil {
				log.Printf("chat stream publish tool call failed (%s): %v", topicLogID(topic), err)
				return true
			}
		}
		return false
	}

	for _, block := range mo.Message.ContentBlocks {
		switch block.Type {
		case schema.ContentBlockTypeAssistantGenText:
			if block.AssistantGenText == nil || block.AssistantGenText.Text == "" {
				continue
			}
			if err := publishEvent(ctx, hub, topic, ChatStreamEvent{
				Type:    "delta",
				Content: block.AssistantGenText.Text,
			}); err != nil {
				log.Printf("chat stream publish message delta failed (%s): %v", topicLogID(topic), err)
				return true
			}
		case schema.ContentBlockTypeFunctionToolCall:
			if block.FunctionToolCall == nil {
				continue
			}
			if err := publishEvent(ctx, hub, topic, ChatStreamEvent{
				Type:        "tool_call",
				Tool:        block.FunctionToolCall.Name,
				ToolDisplay: toolDisplay[block.FunctionToolCall.Name],
				Args:        block.FunctionToolCall.Arguments,
			}); err != nil {
				log.Printf("chat stream publish message tool call failed (%s): %v", topicLogID(topic), err)
				return true
			}
		case schema.ContentBlockTypeFunctionToolResult:
			if publishAgenticToolResult(ctx, hub, topic, block.FunctionToolResult, toolDisplay) {
				return true
			}
		}
	}

	return false
}

// publishAgenticToolResult 把一个工具结果内容块的文本拼出来并推送 tool_result 事件；返回 true 表示已发终态错误、应终止。
func publishAgenticToolResult(ctx context.Context, hub *mercure.Hub, topic string, result *schema.FunctionToolResult, toolDisplay map[string]string) bool {
	if result == nil {
		return false
	}

	var content strings.Builder
	for _, block := range result.Content {
		if block != nil && block.Text != nil {
			content.WriteString(block.Text.Text)
		}
	}

	if err := publishEvent(ctx, hub, topic, ChatStreamEvent{
		Type:        "tool_result",
		Tool:        result.Name,
		ToolDisplay: toolDisplay[result.Name],
		Content:     content.String(),
	}); err != nil {
		log.Printf("chat stream publish agentic tool result failed (%s): %v", topicLogID(topic), err)
		return true
	}
	return false
}

// publishEvent 把一个 ChatStreamEvent 以 JSON 形式发布到指定 Mercure topic。
func publishEvent(ctx context.Context, hub *mercure.Hub, topic string, event ChatStreamEvent) error {
	update, err := newChatStreamUpdate(topic, event)
	if err != nil {
		return fmt.Errorf("marshal chat stream event: %w", err)
	}

	if err := hub.Publish(ctx, update); err != nil {
		return fmt.Errorf("publish chat stream event %s: %w", event.Type, err)
	}

	return nil
}

// publishTerminalEvent 用独立的短超时 ctx 发布结束事件（done/error）。
// 通过另起一个 ctx，结束事件在 parent 已超时或被取消的情况下仍能成功 publish，
// 前端因此始终能拿到生成结束信号并退出“生成中”状态。
func publishTerminalEvent(parent context.Context, hub *mercure.Hub, topic string, event ChatStreamEvent) error {
	ctx, cancel := terminalPublishContext(parent)
	defer cancel()

	return publishEvent(ctx, hub, topic, event)
}

// terminalPublishContext 构造一个仅供结束事件发布使用的独立 ctx。
// 明确从 context.Background 派生（忽略 parent），让 done/error 在 parent 已超时的场景下仍可送达。
func terminalPublishContext(_ context.Context) (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.Background(), chatStreamTerminalPublishTimeout)
}

// newChatStreamUpdate 把 ChatStreamEvent 序列化为 Mercure Update，并分配 UUID。
// 仅做 JSON 编码与 topic 绑定，发布动作由调用方完成。
func newChatStreamUpdate(topic string, event ChatStreamEvent) (*mercure.Update, error) {
	data, err := json.Marshal(event)
	if err != nil {
		return nil, err
	}

	update := &mercure.Update{
		Topics: []string{topic},
		Event: mercure.Event{
			Data: string(data),
		},
	}
	update.AssignUUID()

	return update, nil
}

// toAgenticMessages 把 bridge 入参的 ChatMessage 列表转换成 eino schema.AgenticMessage（Responses 通道）。
// 空 content 丢弃；role 仅支持 system/assistant/user；assistant 历史包成一个 AssistantGenText 内容块。
func toAgenticMessages(messages []ChatMessage) ([]*schema.AgenticMessage, error) {
	result := make([]*schema.AgenticMessage, 0, len(messages))

	for _, msg := range messages {
		content := msg.Content
		if content == "" {
			continue
		}

		switch strings.ToLower(strings.TrimSpace(msg.Role)) {
		case "system":
			result = append(result, schema.SystemAgenticMessage(content))
		case "assistant":
			result = append(result, &schema.AgenticMessage{
				Role:          schema.AgenticRoleTypeAssistant,
				ContentBlocks: []*schema.ContentBlock{schema.NewContentBlock(&schema.AssistantGenText{Text: content})},
			})
		case "user":
			result = append(result, schema.UserAgenticMessage(content))
		default:
			return nil, fmt.Errorf("unsupported chat message role: %s", msg.Role)
		}
	}

	return result, nil
}

// BuildRuntimeChatModel 按供应商协议构造一次运行时 LLM 调用使用的 BaseChatModel。
func BuildRuntimeChatModel(ctx context.Context, provider BridgeProvider, modelID string) (model.BaseChatModel, error) {
	return buildChatModelForStream(ctx, provider, modelID)
}

// BuildAgentChatModel 给 Message 通道的 ReAct agent（如 reception runner）构造模型；底层是包成 ToolCallingChatModel 的 agentic model。
func BuildAgentChatModel(ctx context.Context, provider BridgeProvider, modelID string) (model.BaseChatModel, error) {
	return BuildRuntimeChatModel(ctx, provider, modelID)
}

// BuildAgentAgenticModel 给 AgenticMessage 通道的 ADK agent 构造 model.AgenticModel。
func BuildAgentAgenticModel(ctx context.Context, provider BridgeProvider, modelID string) (model.AgenticModel, error) {
	return buildAgenticModel(ctx, provider, modelID, false)
}

// SupportsAgenticModel 暴露给跨包（如 reception）判定该 provider 是否有可用的 agentic model 组件。
func SupportsAgenticModel(protocol string) bool {
	return supportsAgenticModel(protocol)
}

// buildChatModelForStream 为一次对话构造 eino BaseChatModel：统一走 agentic model，经适配器暴露为
// BaseChatModel/ToolCallingChatModel。无 agentic 组件的协议（如 ollama / openrouter）不提供对话能力，仅用于 embedding。
func buildChatModelForStream(ctx context.Context, provider BridgeProvider, modelID string) (model.BaseChatModel, error) {
	if supportsAgenticModel(provider.Protocol) {
		return newAgenticToolCallingModel(ctx, provider, modelID, false)
	}
	return nil, fmt.Errorf("%w: %s", errUnsupportedProtocol, provider.Protocol)
}

// 凭据脱敏：常见的 OpenAI / Anthropic / Bearer / JWT 风格的 token 经常被上游 SDK 原样
// 拼进错误信息。即便是“看起来无关”的错误，也可能在堆栈里夹带 Authorization 头，
// 因此走 publish / 写日志之前一律先过这层正则。
//
// 正则按特异度排序：先匹配带前缀的（sk-、Bearer），最后才是裸 JWT，让泛模式只接收剩余文本。
var sensitiveTokenPatterns = []struct {
	re   *regexp.Regexp
	repl string
}{
	{regexp.MustCompile(`(?i)sk-[A-Za-z0-9_\-]{16,}`), "[redacted-key]"},
	{regexp.MustCompile(`(?i)Bearer\s+[A-Za-z0-9._\-]+`), "Bearer [redacted]"},
	{regexp.MustCompile(`(?i)(api[\s_-]?key\s*[:=]\s*)[A-Za-z0-9_\-]{8,}`), "${1}[redacted]"},
	{regexp.MustCompile(`eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+`), "[redacted-jwt]"},
}

// scrubSensitive 对任意字符串做凭据脱敏。
// 抽成函数供两处复用：日志（log.Printf）和发布到浏览器的 error 字段。
func scrubSensitive(text string) string {
	for _, p := range sensitiveTokenPatterns {
		text = p.re.ReplaceAllString(text, p.repl)
	}
	return text
}

// sanitizeUpstreamError 把上游错误裁成适合回传给终端用户的短描述。
// 1) 先把已知凭据片段全部替换成占位符（任意错误内容都过这层）；
// 2) 命中“API key 鉴权失败”关键词时归一为统一文案；
// 3) 最后按最大长度截断，让浏览器只收到摘要，整个堆栈 / 远端 HTML 留在服务端日志。
func sanitizeUpstreamError(err error) string {
	if err == nil {
		return ""
	}

	msg := scrubSensitive(err.Error())

	lower := strings.ToLower(msg)
	if strings.Contains(lower, "incorrect api key") ||
		strings.Contains(lower, "invalid api key") ||
		(strings.Contains(lower, "api key") && strings.Contains(lower, "401")) ||
		(strings.Contains(lower, "api key") && strings.Contains(lower, "unauthorized")) {
		return "AI provider authentication failed. Please check the API key."
	}

	if len(msg) > 300 {
		msg = msg[:300] + "…"
	}
	return msg
}

// SanitizeUpstreamError 返回经过凭据脱敏和长度截断的错误摘要，可安全放入面向 UI 的运行时 payload。
func SanitizeUpstreamError(err error) string {
	return sanitizeUpstreamError(err)
}

// topicLogID 把 Mercure topic 折叠成一个安全且足够唯一的日志标识。
//
// Mercure 当前是匿名订阅，完整 topic 字符串就是订阅密钥，窗口期内回放也能拿到历史增量。
// 因此日志中只保留 workspace 段（URL 中本来就可见）+ ulid 段的 sha-256 前 8 字节，
// 让运维既能把同一轮对话的多条日志串起来，又把随机段保留在服务端。
func topicLogID(topic string) string {
	if topic == "" {
		return "<empty>"
	}

	const prefix = "urn:helmdesk:ai-chat:"
	rest := strings.TrimPrefix(topic, prefix)

	idx := strings.LastIndex(rest, ":")
	if idx <= 0 {
		// 格式异常的 topic 走整串 hash，让日志中只出现摘要。
		sum := sha256.Sum256([]byte(topic))
		return "ws=?:hash=" + hex.EncodeToString(sum[:6])
	}

	workspaceID := rest[:idx]
	turn := rest[idx+1:]
	sum := sha256.Sum256([]byte(turn))
	return "ws=" + workspaceID + ":hash=" + hex.EncodeToString(sum[:6])
}
