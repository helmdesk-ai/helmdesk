package reception

import (
	"context"
	"errors"
	"fmt"
	"strings"

	aiintegration "helmdesk/internal/app/integration/ai"
)

// errAllModelsExhausted 在所有候选模型都失败后返回，让 actor 切换到 AI 不可用兜底流程。
var errAllModelsExhausted = errors.New("all model candidates exhausted")

// modelFallbackResult 记录一次带 fallback 的模型调用结果。
type modelFallbackResult struct {
	// usedIndex 是最终成功使用的候选模型在 candidates 中的索引；全部失败时为 -1。
	usedIndex int
	// errors 按候选顺序记录每个模型的调用错误；成功的模型对应位置为 nil。
	errors []error
}

// isRetryableUpstreamError 判断上游 LLM 返回的错误是否值得用备用模型重试。
//
// 可重试：认证失败（401/403）、限流（429）、服务端错误（5xx）、模型不存在。
// 不可重试：请求格式错误（400 非认证类）、上下文超长、网络超时、context 取消。
func isRetryableUpstreamError(err error) bool {
	if err == nil {
		return false
	}

	if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		return false
	}

	if errors.Is(err, aiintegration.ErrUnsupportedProtocol) || errors.Is(err, aiintegration.ErrUnsupportedModelType) {
		return true
	}

	msg := strings.ToLower(err.Error())

	if containsAny(msg, "401", "403", "unauthorized", "forbidden", "authentication", "invalid api key", "incorrect api key", "invalid x-api-key", "permission denied") {
		return true
	}

	if containsAny(msg, "429", "rate limit", "rate_limit", "too many requests", "quota exceeded", "quota_exceeded", "insufficient_quota", "billing") {
		return true
	}

	if containsAny(msg, "500", "502", "503", "internal server error", "bad gateway", "service unavailable", "service_unavailable", "server_error", "overloaded") {
		return true
	}

	if containsAny(msg, "model not found", "model_not_found", "model does not exist", "no such model", "invalid model", "model is not available") {
		return true
	}

	return false
}

// containsAny 检查 s 中是否包含任一子串。
func containsAny(s string, subs ...string) bool {
	for _, sub := range subs {
		if strings.Contains(s, sub) {
			return true
		}
	}
	return false
}

// formatFallbackSummary 为 event payload 生成每个候选模型的错误摘要。
func formatFallbackSummary(candidates []runtimeModel, result modelFallbackResult) []map[string]any {
	summaries := make([]map[string]any, 0, len(result.errors))
	for i, err := range result.errors {
		entry := map[string]any{"index": i}
		if i < len(candidates) {
			entry["model_id"] = candidates[i].Model.ModelID
			entry["provider"] = candidates[i].Provider.Slug
		}
		if err != nil {
			entry["error"] = aiintegration.SanitizeUpstreamError(err)
			entry["retryable"] = isRetryableUpstreamError(err)
		} else {
			entry["error"] = nil
		}
		summaries = append(summaries, entry)
	}
	return summaries
}

// allModelsExhaustedError 把多个模型错误合并成一条可读的错误消息。
func allModelsExhaustedError(candidates []runtimeModel, errs []error) error {
	parts := make([]string, 0, len(errs))
	for i, err := range errs {
		if err == nil {
			continue
		}
		modelID := "unknown"
		if i < len(candidates) {
			modelID = candidates[i].Model.ModelID
		}
		parts = append(parts, fmt.Sprintf("%s: %s", modelID, aiintegration.SanitizeUpstreamError(err)))
	}
	return fmt.Errorf("%w: %s", errAllModelsExhausted, strings.Join(parts, "; "))
}
