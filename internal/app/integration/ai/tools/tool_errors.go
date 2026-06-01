package tools

import (
	"context"
	"encoding/json"
	"errors"

	"github.com/cloudwego/eino/components/tool"
	"github.com/cloudwego/eino/schema"
)

// WrapToolErrors 把工具业务错误转成普通工具结果，让整轮对话在单个工具失败时仍可继续。
// 上下文取消和超时仍以 error 形式上抛，让停止按钮和整体超时正常生效。
func WrapToolErrors(tools []tool.BaseTool) []tool.BaseTool {
	wrapped := make([]tool.BaseTool, 0, len(tools))

	for _, base := range tools {
		invokable, ok := base.(tool.InvokableTool)
		if !ok {
			wrapped = append(wrapped, base)
			continue
		}

		wrapped = append(wrapped, &nonFailingInvokableTool{inner: invokable})
	}

	return wrapped
}

type nonFailingInvokableTool struct {
	inner tool.InvokableTool
}

// Info 直接透传内部工具的元信息，仅做错误包装不改变 Schema。
func (t *nonFailingInvokableTool) Info(ctx context.Context) (*schema.ToolInfo, error) {
	return t.inner.Info(ctx)
}

// InvokableRun 调用内部工具：成功原样返回；上下文取消 / 超时按 error 上抛；
// 其余业务错误被序列化为带 is_error 标记的 JSON 字符串，让对话在工具失败时继续推进。
func (t *nonFailingInvokableTool) InvokableRun(ctx context.Context, argumentsInJSON string, opts ...tool.Option) (string, error) {
	result, err := t.inner.InvokableRun(ctx, argumentsInJSON, opts...)
	if err == nil {
		return result, nil
	}
	if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		return "", err
	}

	return marshalToolError(err), nil
}

type toolErrorResult struct {
	IsError bool   `json:"is_error"`
	Error   string `json:"error"`
}

// marshalToolError 把错误包装成 {is_error,error} 形式的 JSON 字符串，
// 序列化失败时返回固定的兜底 payload，让返回值始终是合法 JSON。
func marshalToolError(err error) string {
	payload, marshalErr := json.Marshal(toolErrorResult{
		IsError: true,
		Error:   err.Error(),
	})
	if marshalErr != nil {
		return `{"is_error":true,"error":"tool call failed"}`
	}

	return string(payload)
}
