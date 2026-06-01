package reception

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/cloudwego/eino/adk"
	"github.com/cloudwego/eino/components/tool"
	"github.com/cloudwego/eino/compose"
	"github.com/cloudwego/eino/schema"
	"github.com/dunglas/frankenphp"

	aiintegration "helmdesk/internal/app/integration/ai"
	aittools "helmdesk/internal/app/integration/ai/tools"
)

// 任务 agent 的关键时间窗口。
const (
	// taskMaxDuration 限制单个任务 agent 总耗时。
	// 给到 1 分钟：覆盖常见的 LLM 多步推理 + 后续工具调用，避免一个慢任务把会话拖死。
	taskMaxDuration = time.Minute

	// taskMaxIterations 限制任务 agent 单轮 LLM ↔ tool 来回上限。
	taskMaxIterations = 8
)

// taskRunInput 封装任务 agent 执行所需的全部上下文，让 taskRunFn 签名在扩展时保持稳定。
// 任务 agent 不读对话历史；Question 必须自包含完成本次任务所需的全部信息。
type taskRunInput struct {
	Question         string
	Provider         aiintegration.BridgeProvider
	Model            aiintegration.BridgeModel
	WorkspaceID      string
	ServiceScenarios []serviceScenario
	KnowledgeBases   []planKnowledgeBase
	McpServers       []aittools.McpServerSpec
	Workers          frankenphp.Workers
}

// taskRunFn 是任务 agent 实际执行入口。
// 抽成函数字段方便测试用 stub 跳过真实 LLM。
//
// 实现里要保证：
//   - 返回的 result 字符串是给接待 agent 看的"任务结论"文本；
//   - ctx 取消时返回 ctx.Err()，由 TaskAgent.Run 统一翻译成 cancelled / timeout 状态。
type taskRunFn func(ctx context.Context, input taskRunInput) (string, error)

// taskCompletion 表示一次任务执行结束时的状态快照。
type taskCompletion struct {
	taskID string
	status taskStatus
	result string
}

// TaskAgent 是一次性的"任务级 ReAct 执行体"。
//
// 接待 agent 通过 dispatch_task 触发一个 TaskAgent，它在独立 goroutine 中跑完
// 一轮 ReAct（挂载知识库检索等工具）就销毁。
// 结果通过 onComplete 回流到接待 actor 的事件循环。
//
// ctx 由 actor 顶层 ctx 派生再叠加 taskMaxDuration 超时：actor 关闭或单任务超时
// 都会让任务自然中断。cancel_task 通过同一个 ctx 直接打断。
type TaskAgent struct {
	id    string
	input taskRunInput

	ctx    context.Context
	cancel context.CancelFunc

	run taskRunFn

	mu     sync.Mutex
	status taskStatus
	result string
}

// newTaskAgent 构造尚未启动的 TaskAgent。
// parentCtx 一般是 actor 的根 ctx；这里再叠一层 WithTimeout 让 taskMaxDuration 生效。
func newTaskAgent(parentCtx context.Context, input taskRunInput, run taskRunFn) *TaskAgent {
	ctx, cancel := context.WithTimeout(parentCtx, taskMaxDuration)
	return &TaskAgent{
		id:     newTaskID(),
		input:  input,
		ctx:    ctx,
		cancel: cancel,
		run:    run,
		status: taskStatusRunning,
	}
}

// ID 返回任务 ID。
func (t *TaskAgent) ID() string { return t.id }

// Status 返回当前任务状态的快照。
func (t *TaskAgent) Status() taskStatus {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.status
}

// Snapshot 返回 (status, lastResult) 二元组，供 query_task 工具使用。
func (t *TaskAgent) Snapshot() (taskStatus, string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.status, t.result
}

// Cancel 通过 ctx 触发任务中断；已经结束的任务再次 Cancel 是 no-op。
func (t *TaskAgent) Cancel() {
	t.cancel()
}

// Start 在独立 goroutine 中运行任务，结束后通过 onComplete 回流结果。
//
// onComplete 保证只会被调用一次：成功 / 失败 / 取消 / 超时 任一终态都触发。
// 调用方一般传入一个把 taskCompletion 推回 actor.events 的闭包。
func (t *TaskAgent) Start(onComplete func(taskCompletion)) {
	go func() {
		defer t.cancel()

		result, err := t.run(t.ctx, t.input)
		final := t.finalize(result, err)
		onComplete(final)
	}()
}

// finalize 统一把 run 返回值翻译成 taskCompletion 并更新内部状态。
//
//   - DeadlineExceeded（err 本身或 ctx.Err()）→ timeout
//   - Canceled（err 本身或 ctx.Err()）→ cancelled
//   - 其它非 nil err → failed（result 字段填错误摘要让接待 agent 能跟访客解释）
//   - err == nil → done（result 是 LLM 输出文本）
//
// 优先判断 err 本身是否是 context 错误，再 fallback 到 ctx.Err()。
// 这样当外部 Cancel() 与 LLM SDK 返回非 ctx 错误同时发生时，分类仍然准确。
func (t *TaskAgent) finalize(result string, err error) taskCompletion {
	t.mu.Lock()
	defer t.mu.Unlock()

	switch {
	case err != nil && (errors.Is(err, context.DeadlineExceeded) || errors.Is(t.ctx.Err(), context.DeadlineExceeded)):
		t.status = taskStatusTimeout
		t.result = "任务执行超时"
	case err != nil && (errors.Is(err, context.Canceled) || errors.Is(t.ctx.Err(), context.Canceled)):
		t.status = taskStatusCancelled
		t.result = ""
	case err != nil:
		t.status = taskStatusFailed
		t.result = err.Error()
	default:
		t.status = taskStatusDone
		t.result = result
	}

	return taskCompletion{
		taskID: t.id,
		status: t.status,
		result: t.result,
	}
}

// defaultTaskRun 是生产环境的 taskRunFn：构造任务级 agentic model + 一次性 ReAct agent。
// 任务 agent 不消费对话历史；系统提示词里一次性拼接所有服务场景的完整指令，让模型自行匹配。
// 知识库与 MCP 工具按方案配置同步挂载。
func defaultTaskRun(ctx context.Context, input taskRunInput) (string, error) {
	if !aiintegration.SupportsAgenticModel(input.Provider.Protocol) {
		return "", fmt.Errorf("provider protocol %s has no agentic model component", input.Provider.Protocol)
	}

	instruction := buildTaskInstruction(input.ServiceScenarios)

	agentTools, mcpHandle, err := buildTaskTools(ctx, input.WorkspaceID, input.KnowledgeBases, input.McpServers, input.Workers)
	if err != nil {
		return "", fmt.Errorf("build task tools: %w", err)
	}
	if mcpHandle != nil {
		defer mcpHandle.Close()
	}

	return runTaskAgentic(ctx, instruction, agentTools, input)
}

// buildTaskUserMessage 构造任务 agent 看到的唯一 user message。
func buildTaskUserMessage(question string) string {
	var b strings.Builder
	b.WriteString("# 问题\n")
	b.WriteString(question)
	return b.String()
}

// buildTaskInstruction 把所有服务场景的完整指令一次性拼接成任务 agent 的系统提示词。
//
// 任务 agent 不读对话历史；本轮的访客需求由 user message（question）单独给出。
// 模型应根据 question 自行匹配到适用场景，按场景指令处理；多个场景指令并存时按编号区分。
func buildTaskInstruction(scenarios []serviceScenario) string {
	var b strings.Builder
	b.WriteString("你是工作区的任务执行专员，负责处理接待 agent 派发的一次性后台任务。\n")
	b.WriteString("你不读历史会话；本次任务的问题完全来自 user message。\n")
	b.WriteString("按下面的服务场景指令执行：先根据问题判断匹配哪个场景，再严格遵循对应指令的输出规范。\n")
	b.WriteString("无法获取所需数据时明确说明，不要编造；最终结论会由接待 agent 转述给访客。\n\n")

	if len(scenarios) == 0 {
		b.WriteString("当前方案未配置任何服务场景。基于常识与可用工具尽力作答，无法回答时明确告知调用方。")
		return b.String()
	}

	b.WriteString("# 服务场景指令\n")
	for i, s := range scenarios {
		fmt.Fprintf(&b, "\n## 场景 %d：%s\n", i+1, s.Name)
		if s.Description != "" {
			fmt.Fprintf(&b, "适用于：%s\n", s.Description)
		}
		b.WriteString("\n")
		b.WriteString(s.Instructions)
		b.WriteString("\n")
	}

	return b.String()
}

// buildTaskTools 为任务 agent 装配工具列表。
//
// 返回的 mcpHandle 可能为 nil；非 nil 时调用方必须 defer Close() 释放 MCP 客户端。
//   - 有知识库且 workers 不为空时挂载 knowledge_search；
//   - 有 MCP 服务时调用 aittools.BuildMcpTools 同步挂载远端工具集；
//   - 所有工具统一经 WrapToolErrors 包装：单个工具业务错误转成普通工具结果，
//     让模型按场景指令里的「无法获取所需数据时明确说明」继续推进，不会让整轮任务直接失败。
func buildTaskTools(
	ctx context.Context,
	workspaceID string,
	kbs []planKnowledgeBase,
	mcpServers []aittools.McpServerSpec,
	workers frankenphp.Workers,
) ([]tool.BaseTool, *aittools.McpToolsHandle, error) {
	tools := make([]tool.BaseTool, 0)

	if len(kbs) > 0 && workers != nil {
		specs := make([]aittools.KnowledgeBaseSpec, len(kbs))
		for i, kb := range kbs {
			specs[i] = aittools.KnowledgeBaseSpec{
				ID:          kb.ID,
				Name:        kb.Name,
				Description: kb.Description,
			}
		}
		kbTool, err := aittools.NewKnowledgeSearchTool(workspaceID, specs, workers)
		if err != nil {
			return nil, nil, fmt.Errorf("build knowledge_search tool: %w", err)
		}
		tools = append(tools, kbTool)
	}

	var mcpHandle *aittools.McpToolsHandle
	if len(mcpServers) > 0 {
		mcpHandle = aittools.BuildMcpTools(ctx, mcpServers)
		tools = append(tools, mcpHandle.Tools...)
	}

	if len(tools) == 0 {
		return nil, mcpHandle, nil
	}
	return aittools.WrapToolErrors(tools), mcpHandle, nil
}

// runTaskAgentic 走 Responses（AgenticMessage）通道跑一次性任务 agent：构造 model.AgenticModel + ADK 泛型 agent。
// 任务 agent 单轮、无对话历史、无中间件，直接走 agentic model 的 ADK 泛型 agent。
func runTaskAgentic(ctx context.Context, instruction string, agentTools []tool.BaseTool, input taskRunInput) (string, error) {
	chat, err := aiintegration.BuildAgentAgenticModel(ctx, input.Provider, input.Model.ModelID)
	if err != nil {
		return "", fmt.Errorf("build agentic chat model: %w", err)
	}

	agent, err := adk.NewTypedChatModelAgent(ctx, &adk.TypedChatModelAgentConfig[*schema.AgenticMessage]{
		Name:          "helmdesk-task",
		Description:   "One-shot task agent that resolves the given question.",
		Instruction:   instruction,
		Model:         chat,
		MaxIterations: taskMaxIterations,
		ToolsConfig: adk.ToolsConfig{
			ToolsNodeConfig: compose.ToolsNodeConfig{
				Tools: agentTools,
			},
		},
	})
	if err != nil {
		return "", fmt.Errorf("build task agent: %w", err)
	}

	runner := adk.NewTypedRunner(adk.TypedRunnerConfig[*schema.AgenticMessage]{
		Agent:           agent,
		EnableStreaming: false,
	})

	iter := runner.Run(ctx, []*schema.AgenticMessage{schema.UserAgenticMessage(buildTaskUserMessage(input.Question))})
	final, err := drainTaskFinalTextAgentic(ctx, iter)
	if err != nil {
		return "", err
	}
	if final == "" {
		return "", errors.New("task agent produced empty result")
	}
	return final, nil
}

// drainTaskFinalTextAgentic 消费 AgenticMessage 事件流，取最后一条非空 assistant 文本（内容块拼接）作为任务结论。
func drainTaskFinalTextAgentic(ctx context.Context, iter *adk.AsyncIterator[*adk.TypedAgentEvent[*schema.AgenticMessage]]) (string, error) {
	final := ""
	for {
		event, ok := iter.Next()
		if !ok {
			return final, nil
		}
		if event.Err != nil {
			return final, event.Err
		}
		if event.Action != nil && event.Action.Interrupted != nil {
			return final, errors.New("task agent requested human approval which is not supported")
		}
		if event.Output != nil && event.Output.MessageOutput != nil {
			mo := event.Output.MessageOutput
			if mo != nil && mo.Message != nil && mo.Message.Role == schema.AgenticRoleTypeAssistant {
				if text := agenticAssistantText(mo.Message); text != "" {
					final = text
				}
			}
		}
		if event.Action != nil && event.Action.Exit {
			return final, nil
		}
		if err := ctx.Err(); err != nil {
			return final, err
		}
	}
}

// agenticAssistantText 把一条 assistant AgenticMessage 的所有文本内容块拼成纯文本。
func agenticAssistantText(msg *schema.AgenticMessage) string {
	var b strings.Builder
	for _, block := range msg.ContentBlocks {
		if block.Type == schema.ContentBlockTypeAssistantGenText && block.AssistantGenText != nil {
			b.WriteString(block.AssistantGenText.Text)
		}
	}
	return b.String()
}
