package reception

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"
	"unicode/utf8"

	"github.com/gin-gonic/gin"
)

const (
	actionStartOrResume = "App\\Actions\\Native\\Reception\\StartOrResumeReceptionSessionBridgeAction"
	actionAppendVisitor = "App\\Actions\\Native\\Reception\\AppendVisitorMessageBridgeAction"
	actionRecallVisitor = "App\\Actions\\Native\\Reception\\RecallVisitorMessageBridgeAction"

	entryModeStandalone = "standalone"
	entryModeWidget     = "widget"

	headerEntryMode   = "X-Helmdesk-Entry-Mode"
	headerQueryParams = "X-Helmdesk-Query-Params"
	// 签名访客身份令牌头：携带渠道签发的 user_token。
	headerVisitorToken = "X-Helmdesk-Visitor-Token"
	// 访客行为信号头：小部件 JS 透传当前页/来源/落地页，供 PHP 落到会话渠道上下文。
	headerVisitorURL      = "X-Helmdesk-Visitor-Url"
	headerVisitorReferrer = "X-Helmdesk-Visitor-Referrer"
	headerVisitorLanding  = "X-Helmdesk-Visitor-Landing"
	headerVisitorEntry    = "X-Helmdesk-Visitor-Entry"
	cookiePrefix          = "helmdesk_visitor_"
	// 与 App\Support\ReceptionSession::TOKEN_LENGTH 对齐。
	sessionTokenLength = 32
	cookieMaxAgeSec    = 60 * 60 * 24 * 365 // 1 年

	// 与 AppendVisitorMessageAction::MAX_CONTENT_LENGTH 保持一致。
	maxContentChars     = 4000
	maxContentReadBytes = maxContentChars * 4

	// 与 AppendVisitorMessageAction::MAX_ATTACHMENT_COUNT 保持一致。
	maxAttachmentCount = 10
)

// 请求体承载消息内容本身；身份、会话与业务参数统一由请求 header 承载。
type sendMessageRequest struct {
	Content         string   `json:"content"`
	AttachmentIDs   []string `json:"attachment_ids"`
	ClientMsgID     string   `json:"client_msg_id"`
	QuotedMessageID string   `json:"quoted_message_id"`
}

type normalizedMessageRequest struct {
	content         string
	attachmentIDs   []string
	clientMsgID     string
	quotedMessageID string
}

const (
	// client_msg_id 与 PHP 侧 conversation_messages.client_msg_id 列长度一致。
	maxClientMsgIDLength = 64
	// quoted_message_id 跟 ULID 一致，长度与消息 ID 字段保持一致。
	maxQuotedMessageIDLength = 64
)

// readMessageContent 读取并校验访客发送的消息请求体，统一裁剪长度、附件数等限制。
func readMessageContent(c *gin.Context) (normalizedMessageRequest, error) {
	body, err := io.ReadAll(io.LimitReader(c.Request.Body, int64(maxContentReadBytes)))
	if err != nil {
		return normalizedMessageRequest{}, errors.New("无法读取请求体")
	}
	defer c.Request.Body.Close()

	var payload sendMessageRequest
	if len(body) > 0 {
		if err := json.Unmarshal(body, &payload); err != nil {
			return normalizedMessageRequest{}, errors.New("请求体必须是合法 JSON")
		}
	}

	content := strings.TrimSpace(payload.Content)
	attachmentIDs := normalizeAttachmentIDs(payload.AttachmentIDs)
	if content == "" && len(attachmentIDs) == 0 {
		return normalizedMessageRequest{}, errors.New("消息内容不能为空")
	}
	if utf8.RuneCountInString(content) > maxContentChars {
		return normalizedMessageRequest{}, errors.New("消息太长，请分段发送")
	}
	if len(attachmentIDs) > maxAttachmentCount {
		return normalizedMessageRequest{}, fmt.Errorf("一次最多发送 %d 个附件", maxAttachmentCount)
	}

	return normalizedMessageRequest{
		content:         content,
		attachmentIDs:   attachmentIDs,
		clientMsgID:     normalizeShortID(payload.ClientMsgID, maxClientMsgIDLength),
		quotedMessageID: normalizeShortID(payload.QuotedMessageID, maxQuotedMessageIDLength),
	}, nil
}

// normalizeShortID 修剪首尾空白并按上限截断。空串会被规范化为空，方便 PHP 侧统一判断 null。
func normalizeShortID(value string, max int) string {
	trimmed := strings.TrimSpace(value)
	if trimmed == "" {
		return ""
	}
	if len(trimmed) > max {
		return trimmed[:max]
	}

	return trimmed
}

// normalizeAttachmentIDs 去除附件 ID 列表中的空白项并裁剪首尾空白。
func normalizeAttachmentIDs(values []string) []string {
	out := make([]string, 0, len(values))
	for _, value := range values {
		trimmed := strings.TrimSpace(value)
		if trimmed != "" {
			out = append(out, trimmed)
		}
	}

	return out
}
