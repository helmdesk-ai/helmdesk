package reception

import (
	"context"
	"errors"
	"io"

	"github.com/cloudwego/eino/adk"
	"github.com/cloudwego/eino/components/model"
	"github.com/cloudwego/eino/schema"
)

type handoffTerminalMiddleware struct {
	*adk.BaseChatModelAgentMiddleware
}

func newHandoffTerminalMiddleware() adk.ChatModelAgentMiddleware {
	return &handoffTerminalMiddleware{
		BaseChatModelAgentMiddleware: &adk.BaseChatModelAgentMiddleware{},
	}
}

func (m *handoffTerminalMiddleware) BeforeAgent(
	ctx context.Context,
	runCtx *adk.ChatModelAgentContext,
) (context.Context, *adk.ChatModelAgentContext, error) {
	if runCtx.ReturnDirectly == nil {
		runCtx.ReturnDirectly = map[string]bool{}
	}
	runCtx.ReturnDirectly[toolNameHandoffToHuman] = true

	return ctx, runCtx, nil
}

func (m *handoffTerminalMiddleware) WrapModel(
	_ context.Context,
	inner model.BaseModel[*schema.Message],
	_ *adk.ModelContext,
) (model.BaseModel[*schema.Message], error) {
	return handoffToolCallFilterModel{inner: inner}, nil
}

type handoffToolCallFilterModel struct {
	inner model.BaseModel[*schema.Message]
}

func (m handoffToolCallFilterModel) Generate(
	ctx context.Context,
	input []*schema.Message,
	opts ...model.Option,
) (*schema.Message, error) {
	msg, err := m.inner.Generate(ctx, input, opts...)
	if err != nil {
		return msg, err
	}

	return keepOnlyHandoffToolCall(msg), nil
}

func (m handoffToolCallFilterModel) Stream(
	ctx context.Context,
	input []*schema.Message,
	opts ...model.Option,
) (*schema.StreamReader[*schema.Message], error) {
	reader, err := m.inner.Stream(ctx, input, opts...)
	if err != nil {
		return nil, err
	}

	msg, concatErr := schema.ConcatMessageStream(reader)
	if concatErr != nil && !errors.Is(concatErr, io.EOF) {
		return nil, concatErr
	}
	if msg == nil {
		return schema.StreamReaderFromArray([]*schema.Message{}), nil
	}

	return schema.StreamReaderFromArray([]*schema.Message{keepOnlyHandoffToolCall(msg)}), nil
}

// keepOnlyHandoffToolCall 只保留同轮第一个转人工工具调用，使本轮以转人工结束。
func keepOnlyHandoffToolCall(msg *schema.Message) *schema.Message {
	if msg == nil || len(msg.ToolCalls) <= 1 {
		return msg
	}

	for _, toolCall := range msg.ToolCalls {
		if toolCall.Function.Name != toolNameHandoffToHuman {
			continue
		}

		cloned := *msg
		cloned.ToolCalls = []schema.ToolCall{toolCall}
		return &cloned
	}

	return msg
}
