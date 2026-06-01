package ai

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/cloudwego/eino/components/model"
	"github.com/cloudwego/eino/schema"
	"github.com/gin-gonic/gin"
)

const replyPolishDeadline = 35 * time.Second
const replyPolishCandidateCount = 3
const replyPolishToolName = "set_reply_candidates"

type ReplyPolishRequest struct {
	Provider BridgeProvider     `json:"provider"`
	Model    BridgeModel        `json:"model"`
	Mode     string             `json:"mode"`
	Content  string             `json:"content"`
	Tone     string             `json:"tone"`
	Context  ReplyPolishContext `json:"context"`
}

type ReplyPolishContext struct {
	TeammateLocale      string               `json:"teammate_locale"`
	VisitorLocale       string               `json:"visitor_locale"`
	ConversationSubject string               `json:"conversation_subject"`
	ConversationSummary string               `json:"conversation_summary"`
	QuotedMessage       *ReplyPolishMessage  `json:"quoted_message"`
	RecentMessages      []ReplyPolishMessage `json:"recent_messages"`
}

type ReplyPolishMessage struct {
	Role          string `json:"role"`
	SenderName    string `json:"sender_name"`
	Content       string `json:"content"`
	ContentLocale string `json:"content_locale"`
	OccurredAt    string `json:"occurred_at"`
}

type ReplyPolishResponse struct {
	Success    bool     `json:"success"`
	Code       string   `json:"code,omitempty"`
	Message    string   `json:"message,omitempty"`
	Candidates []string `json:"candidates,omitempty"`
}

const (
	CodeReplyPolishInvalidPayload = "reply_polish.invalid_payload"
	CodeReplyPolishUnavailable    = "reply_polish.model_unavailable"
	CodeReplyPolishFailed         = "reply_polish.failed"
)

// HandleGenerateReplyPolish 处理收件箱客服回复润色请求。
func HandleGenerateReplyPolish(c *gin.Context) {
	var request ReplyPolishRequest
	if err := c.ShouldBindJSON(&request); err != nil {
		c.JSON(http.StatusUnprocessableEntity, ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishInvalidPayload,
			Message: err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, generateReplyPolish(c.Request.Context(), request))
}

// generateReplyPolish 调用配置的 LLM，生成或改写客服候选回复。
func generateReplyPolish(ctx context.Context, req ReplyPolishRequest) ReplyPolishResponse {
	if req.Model.Type != "llm" {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishUnavailable,
			Message: fmt.Sprintf("model type %q is not an llm model", req.Model.Type),
		}
	}

	if missing := missingBridgeProviderCredentials(req.Provider); len(missing) > 0 {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishUnavailable,
			Message: fmt.Sprintf("missing required credentials: %s", strings.Join(missing, ", ")),
		}
	}

	mode := normalizeReplyPolishMode(req.Mode)
	if mode == "" {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishInvalidPayload,
			Message: fmt.Sprintf("unknown mode: %q", req.Mode),
		}
	}

	content := strings.TrimSpace(req.Content)
	if mode == "rewrite" && content == "" {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishInvalidPayload,
			Message: "content is required when mode is rewrite",
		}
	}

	// 回复改写是轻量文本生成：尽量关掉思考模式/降到最低推理，让客服输入框里的响应更快。
	chatModel, genOptions, err := BuildLightweightChatModel(ctx, req.Provider, req.Model.ModelID)
	if err != nil {
		if errors.Is(err, ErrUnsupportedProtocol) || errors.Is(err, ErrUnsupportedModelType) {
			return ReplyPolishResponse{
				Success: false,
				Code:    CodeReplyPolishUnavailable,
				Message: err.Error(),
			}
		}

		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishFailed,
			Message: err.Error(),
		}
	}

	toolCallingModel, ok := chatModel.(model.ToolCallingChatModel)
	if !ok {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishUnavailable,
			Message: "model does not support tool calling",
		}
	}

	boundModel, err := toolCallingModel.WithTools([]*schema.ToolInfo{buildReplyPolishTool()})
	if err != nil {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishFailed,
			Message: err.Error(),
		}
	}

	options := append(genOptions, forceAgenticToolChoice(replyPolishToolName))
	callCtx, cancel := context.WithTimeout(ctx, replyPolishDeadline)
	response, err := boundModel.Generate(callCtx, buildReplyPolishPrompt(mode, content, req.Tone, req.Context), options...)
	cancel()
	if err != nil {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishFailed,
			Message: err.Error(),
		}
	}
	if response == nil {
		return ReplyPolishResponse{
			Success: false,
			Code:    CodeReplyPolishFailed,
			Message: "empty response",
		}
	}

	candidates, err := parseReplyPolishToolCandidates(response)
	if err != nil {
		return ReplyPolishResponse{Success: false, Code: CodeReplyPolishFailed, Message: err.Error()}
	}

	return ReplyPolishResponse{
		Success:    true,
		Candidates: candidates,
	}
}

// buildReplyPolishTool 定义回复助手的结构化输出工具，强制模型返回三条候选。
func buildReplyPolishTool() *schema.ToolInfo {
	candidateItem := &schema.ParameterInfo{
		Type: schema.Object,
		SubParams: map[string]*schema.ParameterInfo{
			"content": {
				Type:     schema.String,
				Required: true,
				Desc:     "一条可直接发送的客服回复候选",
			},
		},
	}

	return &schema.ToolInfo{
		Name: replyPolishToolName,
		Desc: "返回三条客服回复候选；必须严格给出 3 条。",
		ParamsOneOf: schema.NewParamsOneOfByParams(map[string]*schema.ParameterInfo{
			"candidates": {
				Type:     schema.Array,
				ElemInfo: candidateItem,
				Required: true,
				Desc:     "长度必须为 3 的候选回复数组",
			},
		}),
	}
}

// forceAgenticToolChoice 构造 AgenticMessage 通道使用的强制工具调用选项。
func forceAgenticToolChoice(toolName string) model.Option {
	return model.WithAgenticToolChoice(&schema.AgenticToolChoice{
		Type: schema.ToolChoiceForced,
		Forced: &schema.AgenticForcedToolChoice{
			Tools: []*schema.AllowedTool{{FunctionName: toolName}},
		},
	})
}

// buildReplyPolishPrompt 构造回复助手提示词，按模式生成三条候选。
func buildReplyPolishPrompt(mode string, content string, tone string, context ReplyPolishContext) []*schema.Message {
	var b strings.Builder
	if mode == "reply" {
		b.WriteString("请根据会话上下文，为客服生成三条可发送的回复候选。\n")
		if content != "" {
			b.WriteString("\n客服补充要求：\n")
			b.WriteString(content)
			b.WriteString("\n")
		}
	} else {
		b.WriteString("请改写下面这条客服回复草稿，生成三条不同表达的候选。\n\n")
		b.WriteString("回复草稿：\n")
		b.WriteString(content)
		b.WriteString("\n")
	}
	b.WriteString("\n风格要求：")
	b.WriteString(replyPolishToneInstruction(tone))
	b.WriteString("\n\n可参考的会话上下文：\n")
	b.WriteString(formatReplyPolishContext(context))
	b.WriteString("\n要求：\n")
	if mode == "reply" {
		b.WriteString("1. 使用客服语言输出")
		if strings.TrimSpace(context.TeammateLocale) != "" {
			b.WriteString("（")
			b.WriteString(strings.TrimSpace(context.TeammateLocale))
			b.WriteString("）")
		}
		b.WriteString("，不要直接跟随访客语言；\n")
		b.WriteString("2. 严格基于会话上下文生成回复，不确定时请礼貌说明需要进一步确认；\n")
	} else {
		b.WriteString("1. 必须使用草稿本身的语言输出，不要因为访客语言不同而翻译草稿；\n")
		b.WriteString("2. 只改写表达，不要新增草稿里没有的承诺、政策、价格、时效、退款或技术细节；\n")
	}
	b.WriteString("3. 尽量保留链接、数字、变量、Markdown、emoji 和换行结构；\n")
	b.WriteString("4. 三条候选应明显不同，但都要适合客服直接发送；\n")
	b.WriteString("5. 必须调用 set_reply_candidates 工具返回 3 条候选，不要直接输出文本。\n")

	return []*schema.Message{
		schema.SystemMessage("你是客服回复助手，负责根据模式生成安全、准确、可直接发送的候选回复。"),
		schema.UserMessage(b.String()),
	}
}

// normalizeReplyPolishMode 标准化助手模式，非法值返回空字符串由调用方处理。
func normalizeReplyPolishMode(mode string) string {
	switch strings.TrimSpace(mode) {
	case "reply":
		return "reply"
	case "rewrite":
		return "rewrite"
	default:
		return ""
	}
}

// replyPolishToneInstruction 返回语气选项对应的提示词片段。
func replyPolishToneInstruction(tone string) string {
	switch strings.TrimSpace(tone) {
	case "professional":
		return "更专业、克制、准确，适合 B 端客服沟通。"
	case "friendly":
		return "更亲切、自然、有礼貌，但不要过度热情。"
	case "concise":
		return "更简洁，去掉冗余表达，保留必要信息。"
	default:
		return "保持原语气，只改善清晰度、礼貌度和可读性。"
	}
}

// formatReplyPolishContext 把会话上下文格式化为提示词中的只读参考资料。
func formatReplyPolishContext(context ReplyPolishContext) string {
	var b strings.Builder
	if strings.TrimSpace(context.TeammateLocale) != "" {
		b.WriteString(fmt.Sprintf("- 客服语言：%s\n", strings.TrimSpace(context.TeammateLocale)))
	}
	if strings.TrimSpace(context.VisitorLocale) != "" {
		b.WriteString(fmt.Sprintf("- 访客语言：%s\n", strings.TrimSpace(context.VisitorLocale)))
	}
	if strings.TrimSpace(context.ConversationSubject) != "" {
		b.WriteString(fmt.Sprintf("- 会话主题：%s\n", limitRunes(strings.TrimSpace(context.ConversationSubject), 200)))
	}
	if strings.TrimSpace(context.ConversationSummary) != "" {
		b.WriteString(fmt.Sprintf("- 会话摘要：%s\n", limitRunes(strings.TrimSpace(context.ConversationSummary), 1200)))
	}
	if context.QuotedMessage != nil && strings.TrimSpace(context.QuotedMessage.Content) != "" {
		b.WriteString("- 当前引用消息：\n")
		b.WriteString(formatReplyPolishMessage(*context.QuotedMessage))
	}
	if len(context.RecentMessages) > 0 {
		b.WriteString("- 最近消息：\n")
		for _, message := range context.RecentMessages {
			if strings.TrimSpace(message.Content) == "" {
				continue
			}
			b.WriteString(formatReplyPolishMessage(message))
		}
	}
	if b.Len() == 0 {
		return "无。\n"
	}

	return b.String()
}

// formatReplyPolishMessage 格式化一条最近消息。
func formatReplyPolishMessage(message ReplyPolishMessage) string {
	role := strings.TrimSpace(message.Role)
	if role == "" {
		role = "unknown"
	}
	senderName := strings.TrimSpace(message.SenderName)
	if senderName == "" {
		senderName = role
	}
	locale := strings.TrimSpace(message.ContentLocale)
	if locale != "" {
		locale = fmt.Sprintf(" [%s]", locale)
	}

	return fmt.Sprintf("  %s/%s%s：%s\n", role, senderName, locale, limitRunes(strings.TrimSpace(message.Content), 1000))
}

// parseReplyPolishToolCandidates 从强制工具调用参数里解析三条候选文本。
func parseReplyPolishToolCandidates(message *schema.Message) ([]string, error) {
	var arguments string
	for _, call := range message.ToolCalls {
		if call.Function.Name == replyPolishToolName {
			arguments = call.Function.Arguments
			break
		}
	}
	if strings.TrimSpace(arguments) == "" {
		return nil, fmt.Errorf("missing %s tool call", replyPolishToolName)
	}

	var parsed struct {
		Candidates []struct {
			Content string `json:"content"`
		} `json:"candidates"`
	}
	if err := json.Unmarshal([]byte(arguments), &parsed); err != nil {
		return nil, fmt.Errorf("decode reply candidates tool arguments: %w", err)
	}

	normalized := make([]string, 0, replyPolishCandidateCount)
	for _, candidate := range parsed.Candidates {
		content := strings.TrimSpace(candidate.Content)
		if content == "" {
			continue
		}

		normalized = append(normalized, content)
	}

	if len(normalized) != replyPolishCandidateCount {
		return nil, fmt.Errorf("reply candidates tool must return exactly %d non-empty candidates", replyPolishCandidateCount)
	}

	return normalized, nil
}
