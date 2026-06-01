package reception

import (
	"context"
	"errors"
	"fmt"
	"strings"

	"github.com/cloudwego/eino/components/tool"
	"github.com/cloudwego/eino/components/tool/utils"
)

// 工具名常量集中维护，便于日志和 trace 引用同一份字符串。
const (
	toolNameDispatchTask   = "dispatch_task"
	toolNameCancelTask     = "cancel_task"
	toolNameQueryTask      = "query_task"
	toolNameHandoffToHuman = "handoff_to_human"
)

type dispatchTaskInput struct {
	Question string `json:"question" jsonschema:"description=要交给任务 agent 处理的完整问题，必须包含访客需求、已知参数、约束条件和期望输出。任务 agent 不读历史记录，所需信息必须全部在此字段。"`
}

type dispatchTaskOutput struct {
	TaskID string `json:"task_id,omitempty"`
	Error  string `json:"error,omitempty"`
}

type cancelTaskInput struct {
	TaskID string `json:"task_id" jsonschema:"description=需要取消的任务 ID。"`
}

type cancelTaskOutput struct {
	Ok    bool   `json:"ok"`
	Error string `json:"error,omitempty"`
}

type queryTaskInput struct {
	TaskID string `json:"task_id" jsonschema:"description=要查询状态的任务 ID。"`
}

type queryTaskOutput struct {
	Status     string `json:"status"`
	LastResult string `json:"last_result,omitempty"`
	Error      string `json:"error,omitempty"`
}

type handoffInput struct {
	Reason  string `json:"reason" jsonschema:"description=转人工的原因摘要，例如 user_requested、tool_failure。"`
	Summary string `json:"summary" jsonschema:"description=给坐席看的会话上下文摘要，方便他直接接手；必须使用访客语言。"`
}

type handoffOutput struct {
	Accepted             bool   `json:"accepted"`
	Reason               string `json:"reason"`
	Notice               string `json:"notice"`
	HumanAvailable       bool   `json:"human_available"`
	BusinessHoursSummary string `json:"business_hours_summary,omitempty"`
	NextAvailableAt      string `json:"next_available_at,omitempty"`
}

// buildTools 为当前 actor 装配业务工具。
//
// 每个工具都通过 closure 持有 actor 引用，工具体内通过 phpbridge 回写 PHP 或操作 actor 内部状态。
// 任一工具构造失败都视为运行时配置异常，返回错误让上层 runOneTurn 终止本轮。
func (a *Actor) buildTools() ([]tool.BaseTool, error) {
	tools := make([]tool.BaseTool, 0, 4)

	dispatchTool, err := utils.InferTool(
		toolNameDispatchTask,
		"派发一个后台任务到任务 agent。任务 agent 不读历史，所需上下文（访客需求、参数、约束、期望输出）必须由 question 完整给出；任务结果异步回流后由后续轮次转述给访客。",
		a.invokeDispatchTask,
	)
	if err != nil {
		return nil, fmt.Errorf("build dispatch_task tool: %w", err)
	}
	tools = append(tools, dispatchTool)

	cancelTool, err := utils.InferTool(
		toolNameCancelTask,
		"取消一个尚未完成的后台任务；访客改主意或提供新参数时使用。",
		a.invokeCancelTask,
	)
	if err != nil {
		return nil, fmt.Errorf("build cancel_task tool: %w", err)
	}
	tools = append(tools, cancelTool)

	queryTool, err := utils.InferTool(
		toolNameQueryTask,
		"查询一个后台任务当前状态；访客催促或想确认进度时沿用已有任务。",
		a.invokeQueryTask,
	)
	if err != nil {
		return nil, fmt.Errorf("build query_task tool: %w", err)
	}
	tools = append(tools, queryTool)

	handoffTool, err := utils.InferTool(
		toolNameHandoffToHuman,
		"请求把会话从 AI 接待切到人工坐席；工具会按营业时间和人工可用状态返回 accepted，并直接把 notice 送达访客。",
		a.invokeHandoff,
	)
	if err != nil {
		return nil, fmt.Errorf("build handoff_to_human tool: %w", err)
	}
	tools = append(tools, handoffTool)

	return tools, nil
}

// invokeDispatchTask 启动一个真任务 agent 异步执行：返回 task_id 给 LLM，任务结果后续
// 通过 task_result 事件回流到接待 agent，由它再决定回复、取消或重新 dispatch。
//
// 命中 max_concurrent_tasks_per_conversation 时透回 task_limit_exceeded 让 LLM 重新决策。
func (a *Actor) invokeDispatchTask(_ context.Context, input dispatchTaskInput) (dispatchTaskOutput, error) {
	question := strings.TrimSpace(input.Question)
	if question == "" {
		return dispatchTaskOutput{}, fmt.Errorf("dispatch_task.question must not be empty")
	}

	taskID, err := a.dispatchTask(question)
	if err != nil {
		if errors.Is(err, errTaskLimitExceeded) {
			a.logToolCall(toolNameDispatchTask, map[string]any{
				"question": question,
				"result":   "task_limit_exceeded",
			})
			return dispatchTaskOutput{Error: "task_limit_exceeded"}, nil
		}
		return dispatchTaskOutput{}, err
	}

	a.logToolCall(toolNameDispatchTask, map[string]any{
		"task_id":  taskID,
		"question": question,
	})
	return dispatchTaskOutput{TaskID: taskID}, nil
}

// invokeCancelTask 取消占位任务的回流定时器，找不到时按 task_not_found 反馈给 LLM。
func (a *Actor) invokeCancelTask(_ context.Context, input cancelTaskInput) (cancelTaskOutput, error) {
	taskID := strings.TrimSpace(input.TaskID)
	if taskID == "" {
		return cancelTaskOutput{Ok: false, Error: "task_id_required"}, nil
	}

	if !a.cancelTask(taskID) {
		a.logToolCall(toolNameCancelTask, map[string]any{"task_id": taskID, "result": "task_not_found"})
		return cancelTaskOutput{Ok: false, Error: "task_not_found"}, nil
	}

	a.logToolCall(toolNameCancelTask, map[string]any{"task_id": taskID, "result": "cancelled"})
	return cancelTaskOutput{Ok: true}, nil
}

// invokeQueryTask 返回占位任务的当前状态；任务已被清理时按 task_not_found 反馈。
func (a *Actor) invokeQueryTask(_ context.Context, input queryTaskInput) (queryTaskOutput, error) {
	taskID := strings.TrimSpace(input.TaskID)
	if taskID == "" {
		return queryTaskOutput{Error: "task_id_required"}, nil
	}

	status, lastResult, ok := a.queryTask(taskID)
	if !ok {
		a.logToolCall(toolNameQueryTask, map[string]any{"task_id": taskID, "result": "task_not_found"})
		return queryTaskOutput{Error: "task_not_found"}, nil
	}

	a.logToolCall(toolNameQueryTask, map[string]any{"task_id": taskID, "status": string(status)})
	return queryTaskOutput{Status: string(status), LastResult: lastResult}, nil
}

// invokeHandoff 调 PHP 执行转人工终态工具；PHP 会直接写入 notice。
// accepted=true 表示会话已经切到人工队列，本 actor 在当前轮结束后退出。
func (a *Actor) invokeHandoff(_ context.Context, input handoffInput) (handoffOutput, error) {
	reason := strings.TrimSpace(input.Reason)
	if reason == "" {
		reason = "ai_requested"
	}
	quotedID := a.visitorQuoteID()

	res, err := a.native(
		"App\\Actions\\Native\\Reception\\RequestHandoffBridgeAction",
		a.conversationID, reason, quotedID, strings.TrimSpace(input.Summary),
	)
	if err != nil {
		a.logToolCall(toolNameHandoffToHuman, map[string]any{"reason": reason, "error": err.Error()})
		return handoffOutput{}, err
	}

	output, err := decodeHandoffOutput(res)
	if err != nil {
		a.logToolCall(toolNameHandoffToHuman, map[string]any{"reason": reason, "error": err.Error()})
		return handoffOutput{}, err
	}

	if output.Accepted {
		a.markHandoff()
	}
	a.logToolCall(toolNameHandoffToHuman, map[string]any{
		"reason":   output.Reason,
		"accepted": output.Accepted,
		"summary":  input.Summary,
		"quoted":   quotedID,
	})
	return output, nil
}

// decodeHandoffOutput 把 PHP HandoffDecisionData 还原为 handoff_to_human 工具结果。
func decodeHandoffOutput(raw any) (handoffOutput, error) {
	m, ok := raw.(map[string]any)
	if !ok {
		return handoffOutput{}, fmt.Errorf("unexpected handoff payload shape: %T", raw)
	}

	return handoffOutput{
		Accepted:             boolOf(m["accepted"]),
		Reason:               stringOf(m["reason"]),
		Notice:               stringOf(m["notice"]),
		HumanAvailable:       boolOf(m["human_available"]),
		BusinessHoursSummary: stringOf(m["business_hours_summary"]),
		NextAvailableAt:      stringOf(m["next_available_at"]),
	}, nil
}

// logToolCall 把一次工具调用作为 ConversationEvent 落库；失败仅记日志不阻塞 LLM 主流程。
func (a *Actor) logToolCall(tool string, extra map[string]any) {
	payload := map[string]any{"tool": tool}
	for k, v := range extra {
		payload[k] = v
	}
	a.logEvent("reception_tool_called", payload)
}
