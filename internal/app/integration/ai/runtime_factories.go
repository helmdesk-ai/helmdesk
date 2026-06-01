package ai

import (
	"context"
	"fmt"

	openaiembedding "github.com/cloudwego/eino-ext/components/embedding/openai"
	"github.com/cloudwego/eino/components/embedding"
	"github.com/cloudwego/eino/components/model"
)

// NewEmbedder 根据 BridgeProvider + modelID 构造一个 eino Embedder。
// embedding 统一走 OpenAI 兼容协议（按 base_uri 接入 qwen/ollama 等兼容品牌）。
func NewEmbedder(ctx context.Context, provider BridgeProvider, modelID string) (embedding.Embedder, error) {
	if provider.Protocol != "openai" {
		return nil, fmt.Errorf("%w: %s embedding", errUnsupportedProtocol, provider.Protocol)
	}

	return openaiembedding.NewEmbedder(ctx, buildOpenAIEmbeddingConfig(provider, modelID))
}

// NewChatModel 根据 BridgeProvider + modelID 构造 RAPTOR 摘要使用的 eino ChatModel。
// 该工厂只返回 model.BaseChatModel。RAPTOR 是一次性结构化摘要任务，关思考提速省 token。
func NewChatModel(ctx context.Context, provider BridgeProvider, modelID string) (model.BaseChatModel, error) {
	// 与 buildChatModelForStream 一致：统一走 agentic model 适配器；无 agentic 组件的协议不支持对话。
	if supportsAgenticModel(provider.Protocol) {
		return newAgenticToolCallingModel(ctx, provider, modelID, true)
	}
	return nil, fmt.Errorf("%w: %s", errUnsupportedProtocol, provider.Protocol)
}

// BuildLightweightChatModel 为轻量生成/结构化任务（会话主题、摘要、打标签、客服回复改写等）构造运行时模型。
// 这里会尽量在供应商配置层关闭 thinking；是否强制工具调用由具体任务自己追加 AgenticToolChoice。
func BuildLightweightChatModel(ctx context.Context, provider BridgeProvider, modelID string) (model.BaseChatModel, []model.Option, error) {
	chatModel, err := newAgenticToolCallingModel(ctx, provider, modelID, true)
	if err != nil {
		return nil, nil, err
	}

	return chatModel, nil, nil
}
