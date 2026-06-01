package knowledge

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/cloudwego/eino/schema"

	aiintegration "helmdesk/internal/app/integration/ai"
)

// summarizeDeadline 是 RAPTOR 单次 batch 摘要的整体上限。
// 与 PHP 侧 SUMMARIZE_TIMEOUT_SECONDS=120 对齐，留 5 秒 buffer。
const summarizeDeadline = 115 * time.Second

// summarizeBatches 把一组段落集合，每个集合压成一句中文摘要。
// 同步实现：并发由 PHP 端的 worker 池统一编排，单批同步处理让错误透传链路保持清晰。
func summarizeBatches(ctx context.Context, req SummarizeRequest) SummarizeResponse {
	if req.Model.Type != "llm" {
		return SummarizeResponse{
			Success: false,
			Code:    codeSummarizeFailed,
			Message: fmt.Sprintf("model type %q is not an llm model", req.Model.Type),
		}
	}

	if missing := missingProviderCredentials(req.Provider); len(missing) > 0 {
		return SummarizeResponse{
			Success: false,
			Code:    codeSummarizeUnavailable,
			Message: fmt.Sprintf("missing required credentials: %s", strings.Join(missing, ", ")),
		}
	}

	if len(req.Batches) == 0 {
		return SummarizeResponse{Success: true, Summaries: nil}
	}

	bridgeProvider := aiintegration.BridgeProvider{
		Slug:             req.Provider.Slug,
		Name:             req.Provider.Name,
		Protocol:         req.Provider.Protocol,
		Credentials:      req.Provider.Credentials,
		CredentialFields: convertCredentialFields(req.Provider.CredentialFields),
	}

	chatModel, err := aiintegration.NewChatModel(ctx, bridgeProvider, req.Model.ModelID)
	if err != nil {
		if errors.Is(err, aiintegration.ErrUnsupportedProtocol) || errors.Is(err, aiintegration.ErrUnsupportedModelType) {
			return SummarizeResponse{
				Success: false,
				Code:    codeSummarizeUnavailable,
				Message: err.Error(),
			}
		}
		return SummarizeResponse{
			Success: false,
			Code:    codeSummarizeFailed,
			Message: err.Error(),
		}
	}

	summaries := make([]string, 0, len(req.Batches))
	for batchIdx, batch := range req.Batches {
		callCtx, cancel := context.WithTimeout(ctx, summarizeDeadline)
		messages := buildSummaryPrompt(batch)
		response, err := chatModel.Generate(callCtx, messages)
		cancel()
		if err != nil {
			return SummarizeResponse{
				Success: false,
				Code:    codeSummarizeFailed,
				Message: fmt.Sprintf("batch %d: %s", batchIdx, err.Error()),
			}
		}
		if response == nil {
			return SummarizeResponse{
				Success: false,
				Code:    codeSummarizeFailed,
				Message: fmt.Sprintf("batch %d: empty response", batchIdx),
			}
		}
		summaries = append(summaries, strings.TrimSpace(response.Content))
	}

	return SummarizeResponse{
		Success:   true,
		Summaries: summaries,
	}
}

// buildSummaryPrompt 构造 RAPTOR 单层摘要的提示词。
// 摘要要求：保留事实、覆盖关键术语和数字、严格基于原文，输出中文。
func buildSummaryPrompt(parts []string) []*schema.Message {
	var b strings.Builder
	b.WriteString("以下是来自同一文档的若干段落，请把它们综合为一段精炼摘要：\n\n")
	for i, p := range parts {
		b.WriteString(fmt.Sprintf("【片段 %d】\n", i+1))
		b.WriteString(strings.TrimSpace(p))
		b.WriteString("\n\n")
	}
	b.WriteString("摘要要求：\n")
	b.WriteString("1. 保留事实和关键数字、人名、产品名；\n")
	b.WriteString("2. 不要无中生有，未在片段中出现的信息不要写；\n")
	b.WriteString("3. 输出 1-3 段中文，不要带标题或编号。\n")

	return []*schema.Message{
		schema.SystemMessage("你是一名擅长信息聚合的中文文档总结助手。"),
		schema.UserMessage(b.String()),
	}
}
