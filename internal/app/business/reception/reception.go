// reception 包实现访客聊天相关的 Go 端接口。
package reception

import (
	"log"
	"net/http"
	"strings"

	receptionpayload "helmdesk/internal/app/business/reception/payload"
	"helmdesk/internal/app/config"
	"helmdesk/internal/app/phpbridge"

	"github.com/gin-gonic/gin"
)

// RegisterRoutes 把访客聊天的状态查询与消息发送接口挂载到主路由上。
//
// registry 为共享的接待 actor 注册中心（与 Telegram 等其它渠道复用同一池），
// 由调用方在 wiring 阶段构造并注入。
func RegisterRoutes(router *gin.Engine, cfg *config.Config, registry *Registry) {
	router.GET("/api/chat/:code/state", stateHandler(cfg))
	router.POST("/api/chat/:code/messages", sendMessageHandler(cfg, registry))
	router.POST("/api/chat/:code/messages/:messageId/recall", recallMessageHandler(cfg, registry))
	router.POST("/api/chat/:code/typing", typingHandler(registry))
}

// typingKey 把渠道 code 与访客会话 token 组合成 typing 索引键；用不可见分隔符避免拼接歧义。
func typingKey(code, sessionToken string) string {
	return code + "\x00" + sessionToken
}

// typingHandler 处理访客「正在输入」信号：仅作用于已存在的接待 actor，告知其推迟聚合 flush，
// 让一句话拆成几段连发时 AI 等访客打完再回，而不是逐句作答。
//
// 该端点不回 PHP、不落库、不暴露 conversation_id：会话归属由 Go 在发消息时记下的
// session→conversation 索引解析。无映射或会话已结束时静默返回 204。
func typingHandler(registry *Registry) gin.HandlerFunc {
	return func(c *gin.Context) {
		code := strings.TrimSpace(c.Param("code"))
		sessionToken := readVisitorToken(c, code)
		if code != "" && sessionToken != "" {
			registry.NoteTyping(typingKey(code, sessionToken))
		}
		c.Status(http.StatusNoContent)
	}
}

// stateHandler 返回指定渠道当前会话的快照，必要时通过 PHP 桥接新建或恢复会话。
func stateHandler(cfg *config.Config) gin.HandlerFunc {
	return func(c *gin.Context) {
		code := strings.TrimSpace(c.Param("code"))
		if code == "" {
			c.AbortWithStatus(http.StatusNotFound)
			return
		}

		existingToken := readVisitorToken(c, code)

		entryMode := visitorEntryMode(c)
		userToken := readUserToken(c)
		queryParams := collectVisitorQueryParams(c)

		// 会话状态仍以 Laravel 为准。
		state, err := phpbridge.CallNative(cfg.NativeWorkers, actionStartOrResume, code, existingToken, entryMode, visitorEnvironment(c), userToken, queryParams, visitorClient(c))
		if err != nil {
			abortFromBridgeError(c, err, "reception start_or_resume")
			return
		}

		payload := state.(map[string]any)
		receptionpayload.Normalize(payload)

		writeSessionCookieIfChanged(c, code, existingToken, payload, entryMode)
		receptionpayload.StripInternalStateFields(payload)
		c.JSON(http.StatusOK, payload)
	}
}

// latestVisitorMessageID 从 PHP 桥接返回的会话状态里挑出最近一条访客消息 ID，
// 用作 AI 后续普通回复的 quoted_message_id。state.messages 按时间升序，从尾部反扫，
// 取到首个 role=visitor 的条目即停。
//
// 一次 send 可能产生 1+ 条访客消息（文本 + 多附件）；取最新一条即可——AI 引用客户视角下
// "最后说的那一句"是与人工客服回复一致的 UX。
func latestVisitorMessageID(state map[string]any) string {
	messages, ok := state["messages"].([]any)
	if !ok {
		return ""
	}
	for i := len(messages) - 1; i >= 0; i-- {
		msg, ok := messages[i].(map[string]any)
		if !ok {
			continue
		}
		role, _ := msg["role"].(string)
		if role != "visitor" {
			continue
		}
		id, _ := msg["id"].(string)
		return id
	}
	return ""
}

// sendMessageHandler 接收访客消息并写入会话，AI 接待状态下把消息入队给后台 actor。
//
// AI 回复（以及任何 typing 帧）由 actor 异步通过 Mercure 推送，客户端依赖 conversation topic
// 的 ai_message_created 事件刷新 UI。
func sendMessageHandler(cfg *config.Config, registry *Registry) gin.HandlerFunc {
	return func(c *gin.Context) {
		code := strings.TrimSpace(c.Param("code"))
		if code == "" {
			c.AbortWithStatus(http.StatusNotFound)
			return
		}

		message, err := readMessageContent(c)
		if err != nil {
			c.JSON(http.StatusUnprocessableEntity, gin.H{"message": err.Error()})
			return
		}

		sessionToken := readVisitorToken(c, code)
		entryMode := visitorEntryMode(c)
		userToken := readUserToken(c)
		queryParams := collectVisitorQueryParams(c)

		afterVisitor, err := phpbridge.CallNative(cfg.NativeWorkers, actionAppendVisitor, code, sessionToken, message.content, entryMode, visitorEnvironment(c), message.attachmentIDs, userToken, queryParams, message.clientMsgID, message.quotedMessageID)
		if err != nil {
			abortFromBridgeError(c, err, "reception append_visitor")
			return
		}
		visitorState := afterVisitor.(map[string]any)
		receptionpayload.Normalize(visitorState)

		writeSessionCookieIfChanged(c, code, sessionToken, visitorState, entryMode)

		if !receptionpayload.ShouldSkipPlaceholderAI(visitorState) {
			conversationID, _ := visitorState["conversation_id"].(string)
			if conversationID != "" {
				registry.EnqueueVisitorMessage(conversationID, message.content, latestVisitorMessageID(visitorState))
				// 记下 session→conversation，供后续访客「正在输入」信号反查 actor。
				// 用 PHP 解析出的会话 token（与下发给客户端的 cookie 一致），保证 typing 端点能命中。
				resolvedToken, _ := visitorState["session_token"].(string)
				if resolvedToken == "" {
					resolvedToken = sessionToken
				}
				registry.RememberVisitorSession(typingKey(code, resolvedToken), conversationID)
			} else {
				log.Printf("reception: append_visitor missing conversation_id, skipping actor enqueue (channel=%s)", code)
			}
		}

		receptionpayload.StripInternalStateFields(visitorState)
		c.JSON(http.StatusOK, visitorState)
	}
}

// recallMessageHandler 触发访客撤回当前会话内的指定消息。
// PHP 端打 recalled_at 成功后，同步通知 actor 把内存 history 中对应条目替换为占位，
// 让 LLM 下一轮看到的上下文与访客视角对齐（不会基于已撤回内容继续推理）。
func recallMessageHandler(cfg *config.Config, registry *Registry) gin.HandlerFunc {
	return func(c *gin.Context) {
		code := strings.TrimSpace(c.Param("code"))
		if code == "" {
			c.AbortWithStatus(http.StatusNotFound)
			return
		}

		messageID := strings.TrimSpace(c.Param("messageId"))
		if messageID == "" {
			c.AbortWithStatus(http.StatusNotFound)
			return
		}

		// 撤回请求通过 header 携带会话与身份凭证。
		sessionToken := readVisitorToken(c, code)
		userToken := readUserToken(c)

		result, err := phpbridge.CallNative(cfg.NativeWorkers, actionRecallVisitor, code, sessionToken, messageID, userToken)
		if err != nil {
			abortFromBridgeError(c, err, "reception recall_visitor")
			return
		}

		state, ok := result.(map[string]any)
		if !ok {
			log.Printf("reception recall_visitor: unexpected payload type %T", result)
			c.AbortWithStatus(http.StatusInternalServerError)
			return
		}
		receptionpayload.Normalize(state)

		if conversationID, _ := state["conversation_id"].(string); conversationID != "" {
			registry.NotifyMessageRecalled(conversationID, messageID)
		}

		receptionpayload.StripInternalStateFields(state)
		c.JSON(http.StatusOK, state)
	}
}
