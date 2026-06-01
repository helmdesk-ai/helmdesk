package ai

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/cloudwego/eino/schema"
	"github.com/gin-gonic/gin"
)

const conversationSubjectDeadline = 25 * time.Second

type ConversationSubjectRequest struct {
	Provider BridgeProvider `json:"provider"`
	Model    BridgeModel    `json:"model"`
	Messages []string       `json:"messages"`
}

type ConversationSubjectResponse struct {
	Success bool   `json:"success"`
	Code    string `json:"code,omitempty"`
	Message string `json:"message,omitempty"`
	Subject string `json:"subject,omitempty"`
}

const (
	CodeConversationSubjectInvalidPayload = "conversation_subject.invalid_payload"
	CodeConversationSubjectUnavailable    = "conversation_subject.model_unavailable"
	CodeConversationSubjectFailed         = "conversation_subject.failed"
)

// HandleGenerateConversationSubject 处理会话主题生成请求。
func HandleGenerateConversationSubject(c *gin.Context) {
	var request ConversationSubjectRequest
	if err := c.ShouldBindJSON(&request); err != nil {
		c.JSON(http.StatusUnprocessableEntity, ConversationSubjectResponse{
			Success: false,
			Code:    CodeConversationSubjectInvalidPayload,
			Message: err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, generateConversationSubject(c.Request.Context(), request))
}

// generateConversationSubject 调用配置的 LLM，为会话前几条访客消息生成短主题。
func generateConversationSubject(ctx context.Context, req ConversationSubjectRequest) ConversationSubjectResponse {
	if req.Model.Type != "llm" {
		return ConversationSubjectResponse{
			Success: false,
			Code:    CodeConversationSubjectUnavailable,
			Message: fmt.Sprintf("model type %q is not an llm model", req.Model.Type),
		}
	}

	if missing := missingBridgeProviderCredentials(req.Provider); len(missing) > 0 {
		return ConversationSubjectResponse{
			Success: false,
			Code:    CodeConversationSubjectUnavailable,
			Message: fmt.Sprintf("missing required credentials: %s", strings.Join(missing, ", ")),
		}
	}

	messages := normalizeConversationSubjectMessages(req.Messages)
	if len(messages) == 0 {
		return ConversationSubjectResponse{
			Success: false,
			Code:    CodeConversationSubjectInvalidPayload,
			Message: "messages are required",
		}
	}

	chatModel, genOptions, err := BuildLightweightChatModel(ctx, req.Provider, req.Model.ModelID)
	if err != nil {
		if errors.Is(err, ErrUnsupportedProtocol) || errors.Is(err, ErrUnsupportedModelType) {
			return ConversationSubjectResponse{
				Success: false,
				Code:    CodeConversationSubjectUnavailable,
				Message: err.Error(),
			}
		}

		return ConversationSubjectResponse{
			Success: false,
			Code:    CodeConversationSubjectFailed,
			Message: err.Error(),
		}
	}

	callCtx, cancel := context.WithTimeout(ctx, conversationSubjectDeadline)
	response, err := chatModel.Generate(callCtx, buildConversationSubjectPrompt(messages), genOptions...)
	cancel()
	if err != nil {
		return ConversationSubjectResponse{
			Success: false,
			Code:    CodeConversationSubjectFailed,
			Message: err.Error(),
		}
	}
	if response == nil {
		return ConversationSubjectResponse{
			Success: false,
			Code:    CodeConversationSubjectFailed,
			Message: "empty response",
		}
	}

	return ConversationSubjectResponse{
		Success: true,
		Subject: strings.TrimSpace(response.Content),
	}
}

// buildConversationSubjectPrompt 构造短主题提示词，要求模型跟随访客消息语言输出。
func buildConversationSubjectPrompt(messages []string) []*schema.Message {
	var b strings.Builder
	b.WriteString("请根据以下访客消息，为客服收件箱生成一个简短会话主题。\n\n")
	for i, message := range messages {
		b.WriteString(fmt.Sprintf("访客消息 %d：%s\n", i+1, message))
	}
	b.WriteString("\n要求：\n")
	b.WriteString("1. 使用与访客消息相同的语言；\n")
	b.WriteString("2. 不超过 20 个汉字或 8 个英文单词；\n")
	b.WriteString("3. 仅输出主题本身，省略解释、编号、引号和标点包装；\n")
	b.WriteString("4. 严格基于消息内容概括。\n")

	return []*schema.Message{
		schema.SystemMessage("你负责把客服会话概括为极短、可检索的主题。"),
		schema.UserMessage(b.String()),
	}
}

// normalizeConversationSubjectMessages 清理空消息并限制单条输入长度。
func normalizeConversationSubjectMessages(messages []string) []string {
	normalized := make([]string, 0, len(messages))
	for _, message := range messages {
		message = strings.TrimSpace(message)
		if message == "" {
			continue
		}
		normalized = append(normalized, limitRunes(message, 500))
	}
	return normalized
}
