// telegram 包承接 Telegram Bot 入站 webhook，校验 secret 头后回调 PHP 落库，
// 并复用接待 actor 池让 AI 异步回复（出站投递由 PHP 侧消息观察者负责）。
package telegram

import (
	"encoding/json"
	"io"
	"log"
	"net/http"
	"strings"

	"helmdesk/internal/app/business/reception"
	"helmdesk/internal/app/config"
	"helmdesk/internal/app/phpbridge"

	"github.com/gin-gonic/gin"
)

const (
	// actionReceiveUpdate 是处理 Telegram 入站文本消息的 PHP Native Bridge Action。
	actionReceiveUpdate = "App\\Actions\\Native\\Channel\\Telegram\\ReceiveTelegramUpdateBridgeAction"

	// actionReceiveMedia 是处理 Telegram 入站图片 / 文件消息的 PHP Native Bridge Action。
	actionReceiveMedia = "App\\Actions\\Native\\Channel\\Telegram\\ReceiveTelegramMediaBridgeAction"

	// headerSecretToken 是 Telegram 在 setWebhook 时约定、每次回调携带的校验头。
	headerSecretToken = "X-Telegram-Bot-Api-Secret-Token"

	// inboxStatusAiHandling 表示会话当前由 AI 接待，仅此状态下入站消息才唤起 AI actor。
	inboxStatusAiHandling = "ai_handling"

	// maxBodyBytes 限制单次 webhook 请求体大小，Telegram 文本更新远小于此。
	maxBodyBytes = 1 << 20
)

// RegisterRoutes 挂载 Telegram 入站 webhook 路由，复用传入的接待 actor 注册中心。
func RegisterRoutes(router *gin.Engine, cfg *config.Config, registry *reception.Registry) {
	router.POST("/webhook/telegram/:code", webhookHandler(cfg, registry))
}

// tgUpdate 是 Telegram webhook 推送的更新对象，消费文本与图片 / 文件 message。
type tgUpdate struct {
	Message *tgMessage `json:"message"`
}

type tgMessage struct {
	MessageID int64         `json:"message_id"`
	From      *tgUser       `json:"from"`
	Chat      *tgChat       `json:"chat"`
	Text      string        `json:"text"`
	Caption   string        `json:"caption"`
	Photo     []tgPhotoSize `json:"photo"`
	Document  *tgDocument   `json:"document"`
}

type tgUser struct {
	ID           int64  `json:"id"`
	FirstName    string `json:"first_name"`
	LastName     string `json:"last_name"`
	Username     string `json:"username"`
	LanguageCode string `json:"language_code"`
	IsPremium    bool   `json:"is_premium"`
	IsBot        bool   `json:"is_bot"`
}

type tgChat struct {
	ID   int64  `json:"id"`
	Type string `json:"type"`
}

// tgPhotoSize 是同一图片不同分辨率版本之一，photo 数组按尺寸升序、末尾为最大。
type tgPhotoSize struct {
	FileID string `json:"file_id"`
}

type tgDocument struct {
	FileID   string `json:"file_id"`
	FileName string `json:"file_name"`
	MimeType string `json:"mime_type"`
}

// webhookHandler 处理 Telegram 入站更新：图片 / 文件走媒体 Bridge，文本走文本 Bridge，回调 PHP 落库后按 inbox_status 唤起 AI actor。
//
// secret 头与渠道存储的密钥由 PHP Bridge 校验，不符则透传 403；既非媒体也非文本的更新直接 200 确认避免 Telegram 重投。
func webhookHandler(cfg *config.Config, registry *reception.Registry) gin.HandlerFunc {
	return func(c *gin.Context) {
		code := strings.TrimSpace(c.Param("code"))
		if code == "" {
			c.AbortWithStatus(http.StatusNotFound)
			return
		}

		secret := c.GetHeader(headerSecretToken)

		body, err := io.ReadAll(io.LimitReader(c.Request.Body, maxBodyBytes))
		if err != nil {
			c.AbortWithStatus(http.StatusBadRequest)
			return
		}
		defer c.Request.Body.Close()

		var update tgUpdate
		if len(body) > 0 {
			if err := json.Unmarshal(body, &update); err != nil {
				// 解析失败按已确认处理，避免 Telegram 反复重投畸形请求。
				c.JSON(http.StatusOK, gin.H{"ok": true})
				return
			}
		}

		msg := update.Message
		if msg == nil || msg.From == nil || msg.Chat == nil {
			c.JSON(http.StatusOK, gin.H{"ok": true})
			return
		}

		// 图片 / 文件优先于文本：媒体可附带 caption，caption 作为可唤起 AI 的文本。
		if mediaKind, fileID, fileName, mimeType := extractMedia(msg); fileID != "" {
			result, ok := callBridge(c, cfg, code, actionReceiveMedia,
				code, secret, msg.Chat.ID, msg.From.ID, msg.From.FirstName, msg.From.LastName, msg.From.Username, msg.MessageID,
				mediaKind, fileID, fileName, mimeType, msg.Caption,
			)
			if !ok {
				return
			}
			maybeEnqueue(registry, result, msg.Caption)
			c.JSON(http.StatusOK, gin.H{"ok": true})
			return
		}

		text := strings.TrimSpace(msg.Text)
		if text == "" {
			// 既非媒体也非文本的更新，直接确认接收。
			c.JSON(http.StatusOK, gin.H{"ok": true})
			return
		}

		result, ok := callBridge(c, cfg, code, actionReceiveUpdate,
			code, secret, msg.Chat.ID, msg.From.ID, msg.From.FirstName, msg.From.LastName, msg.From.Username, text, msg.MessageID,
			msg.From.LanguageCode, msg.From.IsPremium, msg.From.IsBot, msg.Chat.Type,
		)
		if !ok {
			return
		}
		maybeEnqueue(registry, result, text)

		c.JSON(http.StatusOK, gin.H{"ok": true})
	}
}

// extractMedia 从消息中识别图片 / 文件并返回媒体类型与 file_id 等信息；非媒体消息返回空 fileID。
func extractMedia(msg *tgMessage) (kind, fileID, fileName, mimeType string) {
	if len(msg.Photo) > 0 {
		// photo 数组按尺寸升序，取末尾最大分辨率。
		largest := msg.Photo[len(msg.Photo)-1]
		if largest.FileID != "" {
			return "image", largest.FileID, "", ""
		}
	}
	if msg.Document != nil && msg.Document.FileID != "" {
		return "file", msg.Document.FileID, msg.Document.FileName, msg.Document.MimeType
	}
	return "", "", "", ""
}

// callBridge 调用 PHP Native Bridge 并统一处理错误：客户端错误透传状态码，其余记日志后 500。
// 返回 (结果, 是否成功)；失败时已写入响应，调用方应直接 return。
func callBridge(c *gin.Context, cfg *config.Config, code, action string, args ...any) (any, bool) {
	result, err := phpbridge.CallNative(cfg.NativeWorkers, action, args...)
	if err != nil {
		if be := phpbridge.AsBridgeError(err); be != nil && be.IsClientError() {
			// secret 不符（403）、渠道不存在（404）等客户端错误透传状态码。
			c.AbortWithStatus(be.StatusCode)
			return nil, false
		}
		log.Printf("telegram webhook bridge failed (channel=%s): %v", code, err)
		c.AbortWithStatus(http.StatusInternalServerError)
		return nil, false
	}
	return result, true
}

// maybeEnqueue 仅当会话由 AI 接待、且本次确实落库了可回复文本时唤起 actor。
// visitorMessageID 为空意味着 /start 命令、纯媒体无 caption 或 webhook 重投幂等命中，无需 AI 回复。
func maybeEnqueue(registry *reception.Registry, result any, text string) {
	state, _ := result.(map[string]any)
	conversationID, _ := state["conversation_id"].(string)
	inboxStatus, _ := state["inbox_status"].(string)
	visitorMessageID, _ := state["visitor_message_id"].(string)

	if conversationID != "" && visitorMessageID != "" && inboxStatus == inboxStatusAiHandling {
		registry.EnqueueVisitorMessage(conversationID, text, visitorMessageID)
	}
}
