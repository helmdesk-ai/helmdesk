package reception

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log"

	"github.com/cloudwego/eino/adk"
	"github.com/cloudwego/eino/compose"
	"github.com/cloudwego/eino/schema"

	aiintegration "helmdesk/internal/app/integration/ai"
)

// runTurnFallbackFn 是带模型 fallback 的推理入口；抽成函数字段方便测试 stub。
type runTurnFallbackFn func(ctx context.Context, in runTurnInput, candidates []runtimeModel) ([]*schema.Message, modelFallbackResult, error)

// runTurnInput 聚合一次 ReAct 推理需要的全部输入。
type runTurnInput struct {
	Actor        *Actor
	SystemPrompt string
	Provider     aiintegration.BridgeProvider
	Model        aiintegration.BridgeModel
	History      []*schema.Message
}

// runTurnWithFallback 按优先级依次尝试候选模型列表，某个模型遇到可重试错误时自动切换下一个。
//
// 全部候选都失败时返回 errAllModelsExhausted，调用方据此触发 AI 不可用兜底流程。
// 不可重试的错误（如 context 超时、400 请求格式错误）会立即终止，不再尝试后续模型。
func runTurnWithFallback(ctx context.Context, in runTurnInput, candidates []runtimeModel) ([]*schema.Message, modelFallbackResult, error) {
	result := modelFallbackResult{usedIndex: -1, errors: make([]error, len(candidates))}

	for i, candidate := range candidates {
		if ctx.Err() != nil {
			result.errors[i] = ctx.Err()
			return nil, result, ctx.Err()
		}

		candidateInput := runTurnInput{
			Actor:        in.Actor,
			SystemPrompt: in.SystemPrompt,
			Provider:     candidate.Provider,
			Model:        candidate.Model,
			History:      in.History,
		}

		msgs, err := in.Actor.runTurn(ctx, candidateInput)
		if err == nil {
			result.usedIndex = i
			result.errors[i] = nil
			return msgs, result, nil
		}

		result.errors[i] = err
		if !isRetryableUpstreamError(err) {
			return msgs, result, err
		}

		log.Printf("reception actor %s: model %s (candidate %d) failed with retryable error, trying next: %v",
			in.Actor.conversationID, candidate.Model.ModelID, i, err)
	}

	return nil, result, allModelsExhaustedError(candidates, result.errors)
}

// runTurn 跑一次 ReAct 推理：构造 ChatModel + 业务工具 → adk.ChatModelAgent → adk.Runner，
// 把 actor.history 整段喂给 runner，再把 runner 产出的 assistant / tool 消息按顺序回收。
//
// 返回值是本轮 LLM 与工具新产生的消息列表（不含传入的 history）；调用方负责把它们追加进
// actor.history，让下一轮 LLM 看到完整的 ReAct 真实轨迹（assistant tool_call + tool result），
// 模式连续，模型无需"猜"上次是怎么调工具的。
//
// 任何 iter.Err、ctx 超时或迭代上限都会返回 error；上层 runOneTurn 据此决定 ended_by 类别。
func runTurn(ctx context.Context, in runTurnInput) ([]*schema.Message, error) {
	chat, err := aiintegration.BuildAgentChatModel(ctx, in.Provider, in.Model.ModelID)
	if err != nil {
		return nil, fmt.Errorf("build chat model: %w", err)
	}

	tools, err := in.Actor.buildTools()
	if err != nil {
		return nil, err
	}

	agent, err := adk.NewChatModelAgent(ctx, &adk.ChatModelAgentConfig{
		Name:          "helmdesk-reception",
		Description:   "接待 agent，负责调度 dispatch_task / cancel_task / query_task / handoff 工具。",
		Instruction:   in.SystemPrompt,
		Model:         chat,
		MaxIterations: maxIterations,
		ToolsConfig: adk.ToolsConfig{
			ToolsNodeConfig: compose.ToolsNodeConfig{
				Tools: tools,
			},
		},
		Handlers: []adk.ChatModelAgentMiddleware{
			newHandoffTerminalMiddleware(),
		},
	})
	if err != nil {
		return nil, fmt.Errorf("build agent: %w", err)
	}

	runner := adk.NewRunner(ctx, adk.RunnerConfig{
		Agent:           agent,
		EnableStreaming: false,
	})

	iter := runner.Run(ctx, in.History)
	return captureTurnMessages(ctx, in.Actor, iter)
}

// captureTurnMessages 把 iter 里的 assistant 与 tool 消息按顺序收集出来，丢弃 user/system（已在入参 history 里）。
//
// 终止条件：
//   - Action.Exit / 没有更多事件：正常结束
//   - Action.Interrupted（needs_human_approval）：接待 agent 不使用人工审批中断
//   - event.Err：LLM 或工具异常
//   - ctx 超时 / 取消：返回 ctx.Err()
//
// 即使中途出错也把已捕获的消息一并返回，让 actor 把"已经发生过的事实"保留到 history。
func captureTurnMessages(ctx context.Context, actor *Actor, iter *adk.AsyncIterator[*adk.AgentEvent]) ([]*schema.Message, error) {
	captured := make([]*schema.Message, 0, 4)

	for {
		event, ok := iter.Next()
		if !ok {
			return captured, nil
		}

		if event.Err != nil {
			return captured, event.Err
		}

		if event.Action != nil && event.Action.Interrupted != nil {
			return captured, fmt.Errorf("reception agent requested human approval")
		}

		if event.Output != nil && event.Output.MessageOutput != nil {
			if msg, err := extractMessage(event.Output.MessageOutput); err != nil {
				log.Printf("reception actor %s: extract message failed: %v", actor.conversationID, err)
			} else if msg != nil {
				captured = append(captured, msg)
			}
		}

		if event.Action != nil && event.Action.Exit {
			return captured, nil
		}

		if err := ctx.Err(); err != nil {
			return captured, err
		}
	}
}

// extractMessage 从一次 MessageOutput 中取出实际的 schema.Message。
//
// 已经写进 actor.history 的 user/system 消息（也就是 runner 的输入）在 ReAct 内部会被 runner 重复
// 暴露给观察者；这里只回收 assistant 与 tool 角色。
//
// 非流式（EnableStreaming=false）下 mo.Message 直接可用；遇到流式则一次性把流读完再返回。
func extractMessage(mo *adk.MessageVariant) (*schema.Message, error) {
	if mo == nil {
		return nil, nil
	}

	if mo.IsStreaming {
		concatenated, err := schema.ConcatMessageStream(mo.MessageStream)
		if err != nil && !errors.Is(err, io.EOF) {
			return nil, err
		}
		if concatenated == nil {
			return nil, nil
		}
		if concatenated.Role == schema.User || concatenated.Role == schema.System {
			return nil, nil
		}
		return concatenated, nil
	}

	msg := mo.Message
	if msg == nil {
		return nil, nil
	}
	if msg.Role == schema.User || msg.Role == schema.System {
		return nil, nil
	}
	return msg, nil
}
