package ai

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/cloudwego/eino/schema"
	"github.com/gin-gonic/gin"
)

const conversationSummaryDeadline = 60 * time.Second
const conversationSummaryMaxContextRunes = 150000

type ConversationSummaryMessage struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

type ConversationSummaryRequest struct {
	Provider        BridgeProvider               `json:"provider"`
	Model           BridgeModel                  `json:"model"`
	Locale          string                       `json:"locale"`
	Messages        []ConversationSummaryMessage `json:"messages"`
	ExistingSummary string                       `json:"existing_summary,omitempty"`
}

type ConversationSummaryResponse struct {
	Success     bool     `json:"success"`
	Code        string   `json:"code,omitempty"`
	Message     string   `json:"message,omitempty"`
	Summary     string   `json:"summary,omitempty"`
	Topics      []string `json:"topics,omitempty"`
	OpenIssues  []string `json:"open_issues,omitempty"`
	Preferences []string `json:"preferences,omitempty"`
}

type ContactSummaryRequest struct {
	Provider            BridgeProvider   `json:"provider"`
	Model               BridgeModel      `json:"model"`
	Locale              string           `json:"locale"`
	ConversationDigests []map[string]any `json:"conversation_digests"`
	ExistingSummary     map[string]any   `json:"existing_summary,omitempty"`
}

type ContactSummaryResponse struct {
	Success        bool     `json:"success"`
	Code           string   `json:"code,omitempty"`
	Message        string   `json:"message,omitempty"`
	ProfileSummary string   `json:"profile_summary,omitempty"`
	OpenIssues     []string `json:"open_issues,omitempty"`
	Preferences    []string `json:"preferences,omitempty"`
	RecentTopics   []string `json:"recent_topics,omitempty"`
}

const (
	CodeConversationSummaryInvalidPayload = "conversation_summary.invalid_payload"
	CodeConversationSummaryUnavailable    = "conversation_summary.model_unavailable"
	CodeConversationSummaryFailed         = "conversation_summary.failed"
)

// HandleGenerateConversationSummary 处理单次会话摘要生成请求。
func HandleGenerateConversationSummary(c *gin.Context) {
	var request ConversationSummaryRequest
	if err := c.ShouldBindJSON(&request); err != nil {
		c.JSON(http.StatusUnprocessableEntity, ConversationSummaryResponse{
			Success: false,
			Code:    CodeConversationSummaryInvalidPayload,
			Message: err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, generateConversationSummary(c.Request.Context(), request))
}

// HandleGenerateContactSummary 处理联系人级 AI 摘要生成请求。
func HandleGenerateContactSummary(c *gin.Context) {
	var request ContactSummaryRequest
	if err := c.ShouldBindJSON(&request); err != nil {
		c.JSON(http.StatusUnprocessableEntity, ContactSummaryResponse{
			Success: false,
			Code:    CodeConversationSummaryInvalidPayload,
			Message: err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, generateContactSummary(c.Request.Context(), request))
}

// generateConversationSummary 调用 LLM 生成访客语言的滚动会话摘要。
func generateConversationSummary(ctx context.Context, req ConversationSummaryRequest) ConversationSummaryResponse {
	if errResp := validateSummaryRuntime(req.Provider, req.Model); errResp != nil {
		return *errResp
	}

	messages := normalizeSummaryMessages(req.Messages)
	if len(messages) == 0 {
		return ConversationSummaryResponse{
			Success: false,
			Code:    CodeConversationSummaryInvalidPayload,
			Message: "messages are required",
		}
	}

	chatModel, genOptions, err := BuildLightweightChatModel(ctx, req.Provider, req.Model.ModelID)
	if err != nil {
		return conversationSummaryRuntimeError(err)
	}

	callCtx, cancel := context.WithTimeout(ctx, conversationSummaryDeadline)
	response, err := chatModel.Generate(callCtx, buildConversationSummaryPrompt(req.Locale, req.ExistingSummary, messages), genOptions...)
	cancel()
	if err != nil {
		return ConversationSummaryResponse{Success: false, Code: CodeConversationSummaryFailed, Message: err.Error()}
	}
	if response == nil {
		return ConversationSummaryResponse{Success: false, Code: CodeConversationSummaryFailed, Message: "empty response"}
	}

	var payload ConversationSummaryResponse
	if err := decodeSummaryJSON(response.Content, &payload); err != nil {
		return ConversationSummaryResponse{Success: false, Code: CodeConversationSummaryFailed, Message: err.Error()}
	}
	payload.Success = true
	payload.Code = ""
	payload.Message = ""
	payload.Summary = strings.TrimSpace(payload.Summary)

	return payload
}

// generateContactSummary 调用 LLM 生成固定字段的联系人级摘要。
func generateContactSummary(ctx context.Context, req ContactSummaryRequest) ContactSummaryResponse {
	if errResp := validateSummaryRuntime(req.Provider, req.Model); errResp != nil {
		return ContactSummaryResponse{Success: errResp.Success, Code: errResp.Code, Message: errResp.Message}
	}

	if len(req.ConversationDigests) == 0 {
		return ContactSummaryResponse{Success: false, Code: CodeConversationSummaryInvalidPayload, Message: "conversation_digests are required"}
	}

	chatModel, genOptions, err := BuildLightweightChatModel(ctx, req.Provider, req.Model.ModelID)
	if err != nil {
		base := conversationSummaryRuntimeError(err)
		return ContactSummaryResponse{Success: base.Success, Code: base.Code, Message: base.Message}
	}

	callCtx, cancel := context.WithTimeout(ctx, conversationSummaryDeadline)
	response, err := chatModel.Generate(callCtx, buildContactSummaryPrompt(req.Locale, req.ExistingSummary, req.ConversationDigests), genOptions...)
	cancel()
	if err != nil {
		return ContactSummaryResponse{Success: false, Code: CodeConversationSummaryFailed, Message: err.Error()}
	}
	if response == nil {
		return ContactSummaryResponse{Success: false, Code: CodeConversationSummaryFailed, Message: "empty response"}
	}

	var payload ContactSummaryResponse
	if err := decodeSummaryJSON(response.Content, &payload); err != nil {
		return ContactSummaryResponse{Success: false, Code: CodeConversationSummaryFailed, Message: err.Error()}
	}
	payload.Success = true
	payload.Code = ""
	payload.Message = ""
	payload.ProfileSummary = strings.TrimSpace(payload.ProfileSummary)

	return payload
}

// validateSummaryRuntime 校验摘要生成可用的模型类型和凭据。
func validateSummaryRuntime(provider BridgeProvider, model BridgeModel) *ConversationSummaryResponse {
	if model.Type != "llm" {
		return &ConversationSummaryResponse{
			Success: false,
			Code:    CodeConversationSummaryUnavailable,
			Message: fmt.Sprintf("model type %q is not an llm model", model.Type),
		}
	}

	if missing := missingBridgeProviderCredentials(provider); len(missing) > 0 {
		return &ConversationSummaryResponse{
			Success: false,
			Code:    CodeConversationSummaryUnavailable,
			Message: fmt.Sprintf("missing required credentials: %s", strings.Join(missing, ", ")),
		}
	}

	return nil
}

// conversationSummaryRuntimeError 映射模型构造和调用阶段的运行时错误。
func conversationSummaryRuntimeError(err error) ConversationSummaryResponse {
	if errors.Is(err, ErrUnsupportedProtocol) || errors.Is(err, ErrUnsupportedModelType) {
		return ConversationSummaryResponse{Success: false, Code: CodeConversationSummaryUnavailable, Message: err.Error()}
	}

	return ConversationSummaryResponse{Success: false, Code: CodeConversationSummaryFailed, Message: err.Error()}
}

// buildConversationSummaryPrompt 构造滚动摘要提示词，要求输出严格 JSON。
func buildConversationSummaryPrompt(locale string, existingSummary string, messages []ConversationSummaryMessage) []*schema.Message {
	var b strings.Builder
	b.WriteString("请为客服系统生成一份单次会话滚动摘要。\n")
	b.WriteString("输出语言必须是访客语言，优先使用 locale: ")
	b.WriteString(strings.TrimSpace(locale))
	b.WriteString("。\n\n")
	if strings.TrimSpace(existingSummary) != "" {
		b.WriteString("已有摘要：\n")
		b.WriteString(strings.TrimSpace(existingSummary))
		b.WriteString("\n\n请把已有摘要仅作为参考，并基于下面的完整消息重新生成当前最终摘要。\n\n")
	}
	b.WriteString("消息记录：\n")
	for i, message := range messages {
		b.WriteString(fmt.Sprintf("%d. [%s] %s\n", i+1, message.Role, message.Content))
	}
	b.WriteString("\n只输出 JSON，不要 Markdown，不要解释。结构：\n")
	b.WriteString(`{"summary":"string","topics":["string"],"open_issues":["string"],"preferences":["string"]}`)
	b.WriteString("\n要求：summary 2-4 句，保留关键诉求、已答复内容和待跟进点；数组每项尽量短。")

	return []*schema.Message{
		schema.SystemMessage("你负责为 AI-First 客服工作台生成准确、克制、可接手的会话摘要。"),
		schema.UserMessage(b.String()),
	}
}

// buildContactSummaryPrompt 构造联系人级摘要提示词，要求输出固定字段 JSON。
func buildContactSummaryPrompt(locale string, existingSummary map[string]any, digests []map[string]any) []*schema.Message {
	var b strings.Builder
	b.WriteString("请基于同一联系人的多次会话摘要，生成联系人级 AI 摘要。\n")
	b.WriteString("输出语言必须是访客语言，优先使用 locale: ")
	b.WriteString(strings.TrimSpace(locale))
	b.WriteString("。\n\n")
	if len(existingSummary) > 0 {
		if encoded, err := json.Marshal(existingSummary); err == nil {
			b.WriteString("已有联系人摘要：\n")
			b.Write(encoded)
			b.WriteString("\n\n")
		}
	}
	if encoded, err := json.Marshal(digests); err == nil {
		b.WriteString("最近会话摘要和事实：\n")
		b.Write(encoded)
		b.WriteString("\n\n")
	}
	b.WriteString("只输出 JSON，不要 Markdown，不要解释。结构：\n")
	b.WriteString(`{"profile_summary":"string","open_issues":["string"],"preferences":["string"],"recent_topics":["string"]}`)
	b.WriteString("\n要求：profile_summary 1-3 句；open_issues 只放仍需跟进事项；preferences 只放稳定偏好；recent_topics 放最近主题。")

	return []*schema.Message{
		schema.SystemMessage("你负责维护客服联系人级别的长期上下文摘要，字段稳定、内容简洁。"),
		schema.UserMessage(b.String()),
	}
}

// normalizeSummaryMessages 清理空消息，并在 Go 边界兜底执行 150K 字符上下文预算。
func normalizeSummaryMessages(messages []ConversationSummaryMessage) []ConversationSummaryMessage {
	normalized := make([]ConversationSummaryMessage, 0, len(messages))
	remaining := conversationSummaryMaxContextRunes

	for _, message := range messages {
		role := strings.TrimSpace(message.Role)
		content := strings.TrimSpace(message.Content)
		if role == "" || content == "" {
			continue
		}

		runes := []rune(content)
		if len(runes) > remaining {
			content = string(runes[:remaining])
			runes = []rune(content)
		}

		normalized = append(normalized, ConversationSummaryMessage{Role: role, Content: content})
		remaining -= len(runes)
		if remaining <= 0 {
			break
		}
	}

	return normalized
}

// decodeSummaryJSON 从模型输出中提取 JSON 对象并解码。
func decodeSummaryJSON(content string, target any) error {
	object, err := extractJSONObject(content)
	if err != nil {
		return err
	}

	if err := json.Unmarshal([]byte(object), target); err != nil {
		return fmt.Errorf("decode summary json: %w", err)
	}

	return nil
}

// extractJSONObject 容忍模型外包一层 Markdown fence，但只接受对象 JSON。
func extractJSONObject(content string) (string, error) {
	trimmed := strings.TrimSpace(content)
	if trimmed == "" {
		return "", fmt.Errorf("empty summary response")
	}

	if strings.HasPrefix(trimmed, "```") {
		lines := strings.Split(trimmed, "\n")
		if len(lines) >= 2 {
			lines = lines[1:]
			if strings.HasPrefix(strings.TrimSpace(lines[len(lines)-1]), "```") {
				lines = lines[:len(lines)-1]
			}
			trimmed = strings.TrimSpace(strings.Join(lines, "\n"))
		}
	}

	start := strings.Index(trimmed, "{")
	end := strings.LastIndex(trimmed, "}")
	if start < 0 || end < start {
		return "", fmt.Errorf("summary response is not a json object")
	}

	return trimmed[start : end+1], nil
}
