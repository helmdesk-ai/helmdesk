package ai

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strings"
	"time"

	"github.com/cloudwego/eino/components/model"
	"github.com/cloudwego/eino/schema"
	"github.com/gin-gonic/gin"
)

const conversationTagsDeadline = 60 * time.Second

// selectConversationTagsToolName 是会话打标签使用的结构化输出工具名。
// 把 tag_id 约束为词表枚举，从结构上杜绝同名跨组标签被混淆；工具走 auto 调用。
const selectConversationTagsToolName = "select_conversation_tags"

// ConversationTagCandidate 是受控词表里的一个候选会话标签。
// Description 同时承担「AI 识别说明」职责：作为模型判断该不该打这个标签的依据。
type ConversationTagCandidate struct {
	TagID       string `json:"tag_id"`
	Name        string `json:"name"`
	Description string `json:"description,omitempty"`
	Group       string `json:"group,omitempty"`
}

// ConversationTagsRequest 由 PHP 侧组装：会话上下文（摘要 + 消息）+ 受控词表候选。
type ConversationTagsRequest struct {
	Provider   BridgeProvider               `json:"provider"`
	Model      BridgeModel                  `json:"model"`
	Locale     string                       `json:"locale"`
	Summary    string                       `json:"summary,omitempty"`
	Messages   []ConversationSummaryMessage `json:"messages"`
	Candidates []ConversationTagCandidate   `json:"candidates"`
}

// ConversationTagSelection 是模型从词表中选中的一个标签及其置信度与判断依据。
type ConversationTagSelection struct {
	TagID      string  `json:"tag_id"`
	Name       string  `json:"name"`
	Confidence float64 `json:"confidence"`
	Reason     string  `json:"reason,omitempty"`
}

// ConversationTagsResponse 是会话打标签的结果；Tags 可为空（无合适标签是合法结果）。
type ConversationTagsResponse struct {
	Success bool                       `json:"success"`
	Code    string                     `json:"code,omitempty"`
	Message string                     `json:"message,omitempty"`
	Tags    []ConversationTagSelection `json:"tags,omitempty"`
}

const (
	CodeConversationTagsInvalidPayload = "conversation_tags.invalid_payload"
	CodeConversationTagsUnavailable    = "conversation_tags.model_unavailable"
	CodeConversationTagsFailed         = "conversation_tags.failed"
)

// HandleGenerateConversationTags 处理会话自动打标签请求：从受控词表中选出适用标签。
func HandleGenerateConversationTags(c *gin.Context) {
	var request ConversationTagsRequest
	if err := c.ShouldBindJSON(&request); err != nil {
		c.JSON(http.StatusUnprocessableEntity, ConversationTagsResponse{
			Success: false,
			Code:    CodeConversationTagsInvalidPayload,
			Message: err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, generateConversationTags(c.Request.Context(), request))
}

// generateConversationTags 绑定 select_conversation_tags 工具（auto，不强制调用）让模型从受控词表中选标签。
//
// 这里刻意不走 eino 的 ReAct/ChatModelAgent 循环：工具不真正执行、也不回灌结果，
// 单次 Generate 让模型自行决定是否调用 select_conversation_tags，其调用参数本身就是结果。
// 标签工具保持 auto：无合适标签时模型可以不调用工具或返回空数组；结构正确性由 tag_id 枚举
// + parseSelectedTags 词表白名单兜底保证。
func generateConversationTags(ctx context.Context, req ConversationTagsRequest) ConversationTagsResponse {
	if errResp := validateSummaryRuntime(req.Provider, req.Model); errResp != nil {
		return ConversationTagsResponse{Success: errResp.Success, Code: errResp.Code, Message: errResp.Message}
	}

	messages := normalizeSummaryMessages(req.Messages)
	if len(messages) == 0 && strings.TrimSpace(req.Summary) == "" {
		return ConversationTagsResponse{
			Success: false,
			Code:    CodeConversationTagsInvalidPayload,
			Message: "summary or messages are required",
		}
	}

	if len(req.Candidates) == 0 {
		// 没有受控词表时不调用模型，直接返回空结果（合法）。
		return ConversationTagsResponse{Success: true}
	}

	chatModel, genOptions, err := BuildLightweightChatModel(ctx, req.Provider, req.Model.ModelID)
	if err != nil {
		base := conversationSummaryRuntimeError(err)
		return ConversationTagsResponse{Success: base.Success, Code: base.Code, Message: base.Message}
	}

	toolCallingModel, ok := chatModel.(model.ToolCallingChatModel)
	if !ok {
		return ConversationTagsResponse{
			Success: false,
			Code:    CodeConversationTagsUnavailable,
			Message: "model does not support tool calling",
		}
	}

	tool := buildSelectConversationTagsTool(req.Candidates)
	boundModel, err := toolCallingModel.WithTools([]*schema.ToolInfo{tool})
	if err != nil {
		return ConversationTagsResponse{Success: false, Code: CodeConversationTagsFailed, Message: err.Error()}
	}

	callCtx, cancel := context.WithTimeout(ctx, conversationTagsDeadline)
	response, err := boundModel.Generate(
		callCtx,
		buildConversationTagsPrompt(req.Locale, req.Summary, messages, req.Candidates),
		genOptions...,
	)
	cancel()
	if err != nil {
		return ConversationTagsResponse{Success: false, Code: CodeConversationTagsFailed, Message: err.Error()}
	}
	if response == nil {
		return ConversationTagsResponse{Success: false, Code: CodeConversationTagsFailed, Message: "empty response"}
	}

	tags, err := parseSelectedTags(response, req.Candidates)
	if err != nil {
		return ConversationTagsResponse{Success: false, Code: CodeConversationTagsFailed, Message: err.Error()}
	}

	log.Printf("[conversation-tags] model=%s candidates=%d selected=%d", req.Model.ModelID, len(req.Candidates), len(tags))

	return ConversationTagsResponse{Success: true, Tags: tags}
}

// buildSelectConversationTagsTool 用词表动态构建结构化输出工具：tag_id 字段约束为词表枚举。
func buildSelectConversationTagsTool(candidates []ConversationTagCandidate) *schema.ToolInfo {
	tagIDs := make([]string, 0, len(candidates))
	for _, candidate := range candidates {
		if tagID := strings.TrimSpace(candidate.TagID); tagID != "" {
			tagIDs = append(tagIDs, tagID)
		}
	}

	tagItem := &schema.ParameterInfo{
		Type: schema.Object,
		SubParams: map[string]*schema.ParameterInfo{
			"tag_id": {
				Type:     schema.String,
				Enum:     tagIDs,
				Required: true,
				Desc:     "标签 ID，必须取自给定枚举值之一",
			},
			"name": {
				Type:     schema.String,
				Required: true,
				Desc:     "标签名，必须与 tag_id 对应的候选标签一致",
			},
			"confidence": {
				Type:     schema.Number,
				Required: true,
				Desc:     "0~1 的置信度",
			},
			"reason": {
				Type: schema.String,
				Desc: "打该标签的一句话依据，引用会话中的证据",
			},
		},
	}

	return &schema.ToolInfo{
		Name: selectConversationTagsToolName,
		Desc: "从受控词表中为本次会话选出贴切的标签；没有贴切标签时 tags 传空数组。",
		ParamsOneOf: schema.NewParamsOneOfByParams(map[string]*schema.ParameterInfo{
			"tags": {
				Type:     schema.Array,
				ElemInfo: tagItem,
				Required: true,
				Desc:     "选中的标签列表，可为空数组",
			},
		}),
	}
}

// parseSelectedTags 从模型的工具调用参数里解析标签；tag_id 已被枚举约束，这里只做去重与词表内的兜底校验。
func parseSelectedTags(message *schema.Message, candidates []ConversationTagCandidate) ([]ConversationTagSelection, error) {
	var arguments string
	for _, call := range message.ToolCalls {
		if call.Function.Name == selectConversationTagsToolName {
			arguments = call.Function.Arguments
			break
		}
	}
	if arguments == "" {
		// auto 下模型可能不调用工具，视为未选中任何标签。
		return nil, nil
	}

	var parsed struct {
		Tags []ConversationTagSelection `json:"tags"`
	}
	if err := json.Unmarshal([]byte(arguments), &parsed); err != nil {
		return nil, fmt.Errorf("decode tool arguments: %w", err)
	}

	allowed := make(map[string]ConversationTagCandidate, len(candidates))
	for _, candidate := range candidates {
		if tagID := strings.TrimSpace(candidate.TagID); tagID != "" {
			allowed[tagID] = candidate
		}
	}

	seen := make(map[string]struct{}, len(parsed.Tags))
	selected := make([]ConversationTagSelection, 0, len(parsed.Tags))
	for _, tag := range parsed.Tags {
		tagID := strings.TrimSpace(tag.TagID)
		candidate, ok := allowed[tagID]
		if !ok || tagID == "" {
			continue
		}
		if _, dup := seen[tagID]; dup {
			continue
		}
		seen[tagID] = struct{}{}
		tag.TagID = tagID
		tag.Name = candidate.Name
		selected = append(selected, tag)
	}

	return selected, nil
}

// buildConversationTagsPrompt 构造打标签提示词：提供词表语义与会话上下文。
// tag_id 的合法性由工具枚举在解码层保证，提示词只需说明语义与「宁缺毋滥」。
func buildConversationTagsPrompt(locale string, summary string, messages []ConversationSummaryMessage, candidates []ConversationTagCandidate) []*schema.Message {
	var b strings.Builder
	b.WriteString("请判断下面这次客服会话适合打哪些标签，并调用 select_conversation_tags 工具返回结果。\n")
	b.WriteString("要求：可以选择多个标签；宁缺毋滥，只在证据充分时才选；没有贴切标签时 tags 传空数组；confidence 取 0~1，reason 用一句话引用会话中的依据。\n\n")

	b.WriteString("可选标签及其含义：\n")
	for _, candidate := range candidates {
		tagID := strings.TrimSpace(candidate.TagID)
		name := strings.TrimSpace(candidate.Name)
		if tagID == "" || name == "" {
			continue
		}
		b.WriteString("- ")
		b.WriteString("tag_id=")
		b.WriteString(tagID)
		b.WriteString("；")
		if group := strings.TrimSpace(candidate.Group); group != "" {
			b.WriteString("分组=")
			b.WriteString(group)
			b.WriteString("；")
		}
		b.WriteString("标签=")
		b.WriteString(name)
		if desc := strings.TrimSpace(candidate.Description); desc != "" {
			b.WriteString("：")
			b.WriteString(desc)
		}
		b.WriteString("\n")
	}
	b.WriteString("\n")

	if strings.TrimSpace(summary) != "" {
		b.WriteString("会话摘要：\n")
		b.WriteString(strings.TrimSpace(summary))
		b.WriteString("\n\n")
	}

	if len(messages) > 0 {
		b.WriteString("会话消息：\n")
		for i, message := range messages {
			b.WriteString(fmt.Sprintf("%d. [%s] %s\n", i+1, message.Role, message.Content))
		}
		b.WriteString("\n")
	}

	b.WriteString("reason 使用的语言参考 locale: ")
	b.WriteString(strings.TrimSpace(locale))
	b.WriteString("。")

	return []*schema.Message{
		schema.SystemMessage("你负责为 AI-First 客服工作台给会话打受控标签，只在证据充分时才打标签。"),
		schema.UserMessage(b.String()),
	}
}
