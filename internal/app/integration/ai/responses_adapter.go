package ai

import (
	"context"
	"fmt"
	"strings"

	"github.com/cloudwego/eino/components/model"
	"github.com/cloudwego/eino/schema"
)

// agenticToolCallingModel 把任意 eino agentic model（model.AgenticModel，AgenticMessage 通道）适配成
// model.ToolCallingChatModel（Message 通道），让所有基于 BaseChatModel/ToolCallingChatModel 的消费方
// （打标签、摘要、主题、改写、RAPTOR、reception ReAct agent）在不改自身代码的前提下整体跑在 agentic model 上。
//
// 适配层负责两件事：schema.Message ↔ schema.AgenticMessage 的双向转换，以及标准 WithTools 透传。
//
// Stream 以一次性 Generate 包成单块流返回：当前没有消费方对该适配器做增量流式（实时对话走原生 typed-agentic 路径），
// 这样既满足接口、又避免 AgenticMessage 流式增量转换的额外复杂度。
type agenticToolCallingModel struct {
	inner model.AgenticModel
	tools []*schema.ToolInfo
}

var _ model.ToolCallingChatModel = (*agenticToolCallingModel)(nil)

// newAgenticToolCallingModel 按 provider 协议构造 agentic model 并包成 ToolCallingChatModel 适配器。
// disableThinking 透传给 buildAgenticModel：轻量任务（打标签/摘要/改写）传 true 以尽量关思考提速。
func newAgenticToolCallingModel(ctx context.Context, provider BridgeProvider, modelID string, disableThinking bool) (*agenticToolCallingModel, error) {
	inner, err := buildAgenticModel(ctx, provider, modelID, disableThinking)
	if err != nil {
		return nil, err
	}
	return &agenticToolCallingModel{inner: inner}, nil
}

// Generate 把 Message 历史转成 AgenticMessage 调用 agentic model，再把结果转回 Message。
func (m *agenticToolCallingModel) Generate(ctx context.Context, input []*schema.Message, opts ...model.Option) (*schema.Message, error) {
	agenticInput, err := messagesToAgentic(input)
	if err != nil {
		return nil, err
	}

	resp, err := m.inner.Generate(ctx, agenticInput, m.agenticOptions(opts)...)
	if err != nil {
		return nil, err
	}
	return agenticToMessage(resp), nil
}

// Stream 以非增量方式满足接口：先 Generate 再包成单块流。
func (m *agenticToolCallingModel) Stream(ctx context.Context, input []*schema.Message, opts ...model.Option) (*schema.StreamReader[*schema.Message], error) {
	resp, err := m.Generate(ctx, input, opts...)
	if err != nil {
		return nil, err
	}
	return schema.StreamReaderFromArray([]*schema.Message{resp}), nil
}

// WithTools 绑定工具：返回一个带工具的副本，工具在每次 Generate 时作为 agentic model 选项下发。
func (m *agenticToolCallingModel) WithTools(tools []*schema.ToolInfo) (model.ToolCallingChatModel, error) {
	clone := *m
	clone.tools = tools
	return &clone, nil
}

// agenticOptions 把调用方传入的标准工具列表翻译成 agentic model 能识别的 WithTools 选项。
func (m *agenticToolCallingModel) agenticOptions(opts []model.Option) []model.Option {
	common := model.GetCommonOptions(&model.Options{}, opts...)

	tools := m.tools
	if len(common.Tools) > 0 {
		tools = common.Tools
	}

	out := make([]model.Option, 0, 2)
	if len(tools) > 0 {
		out = append(out, model.WithTools(tools))
	}
	if common.AgenticToolChoice != nil {
		out = append(out, model.WithAgenticToolChoice(common.AgenticToolChoice))
	}

	return out
}

// messagesToAgentic 把 schema.Message 历史（含 assistant 工具调用、tool 角色的工具结果）转成 AgenticMessage。
// 角色映射遵循 agentic model 的输入约定：工具调用挂在 assistant 上，工具结果挂在 user 上。
func messagesToAgentic(messages []*schema.Message) ([]*schema.AgenticMessage, error) {
	result := make([]*schema.AgenticMessage, 0, len(messages))

	for _, msg := range messages {
		if msg == nil {
			continue
		}

		switch msg.Role {
		case schema.System:
			result = append(result, schema.SystemAgenticMessage(msg.Content))
		case schema.User:
			result = append(result, schema.UserAgenticMessage(msg.Content))
		case schema.Assistant:
			blocks := make([]*schema.ContentBlock, 0, 1+len(msg.ToolCalls))
			if msg.Content != "" {
				blocks = append(blocks, schema.NewContentBlock(&schema.AssistantGenText{Text: msg.Content}))
			}
			for _, tc := range msg.ToolCalls {
				blocks = append(blocks, schema.NewContentBlock(&schema.FunctionToolCall{
					CallID:    tc.ID,
					Name:      tc.Function.Name,
					Arguments: tc.Function.Arguments,
				}))
			}
			result = append(result, &schema.AgenticMessage{Role: schema.AgenticRoleTypeAssistant, ContentBlocks: blocks})
		case schema.Tool:
			result = append(result, &schema.AgenticMessage{
				Role: schema.AgenticRoleTypeUser,
				ContentBlocks: []*schema.ContentBlock{schema.NewContentBlock(&schema.FunctionToolResult{
					CallID: msg.ToolCallID,
					Name:   msg.ToolName,
					Content: []*schema.FunctionToolResultContentBlock{{
						Type: schema.FunctionToolResultContentBlockTypeText,
						Text: &schema.UserInputText{Text: msg.Content},
					}},
				})},
			})
		default:
			return nil, fmt.Errorf("unsupported message role for responses: %s", msg.Role)
		}
	}

	return result, nil
}

// agenticToMessage 把一条 assistant AgenticMessage 转回 schema.Message：文本块拼成 Content，工具调用块转成 ToolCalls。
func agenticToMessage(msg *schema.AgenticMessage) *schema.Message {
	if msg == nil {
		return nil
	}

	out := &schema.Message{Role: schema.Assistant}
	var content strings.Builder

	for _, block := range msg.ContentBlocks {
		switch block.Type {
		case schema.ContentBlockTypeAssistantGenText:
			if block.AssistantGenText != nil {
				content.WriteString(block.AssistantGenText.Text)
			}
		case schema.ContentBlockTypeReasoning:
			if block.Reasoning != nil {
				out.ReasoningContent += block.Reasoning.Text
			}
		case schema.ContentBlockTypeFunctionToolCall:
			if block.FunctionToolCall != nil {
				out.ToolCalls = append(out.ToolCalls, schema.ToolCall{
					ID:   block.FunctionToolCall.CallID,
					Type: "function",
					Function: schema.FunctionCall{
						Name:      block.FunctionToolCall.Name,
						Arguments: block.FunctionToolCall.Arguments,
					},
				})
			}
		}
	}

	out.Content = content.String()
	return out
}
