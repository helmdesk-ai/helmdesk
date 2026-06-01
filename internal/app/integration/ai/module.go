package ai

import (
	"log"

	"github.com/dunglas/frankenphp"
	"github.com/dunglas/mercure"
	"github.com/gin-gonic/gin"
)

// Module 注册 AI 业务在内部桥接路由组下的所有端点。
//
// 通过 BridgeModule 形态暴露，交由 routes.RegisterInternalBridge 统一挂载，
// 让新业务的接入无需触及 routes 包。
//
// hub 可为 nil：此时跳过依赖 Mercure 的实时对话路由，仅暴露 provider 校验等同步端点。
// 在尚未初始化 Mercure 的上下文（例如单元测试）里仍可直接使用本 Module。
//
// nativeWorkers 用于本地工具（如 knowledge_search）反向调用 PHP Bridge Action；nil 时这些工具会优雅降级。
func Module(hub *mercure.Hub, nativeWorkers frankenphp.Workers) func(*gin.RouterGroup) {
	return func(group *gin.RouterGroup) {
		providers := group.Group("/ai/providers")
		providers.POST("/validate", HandleValidate)
		providers.POST("/check", HandleCheck)

		conversationSubject := group.Group("/ai/conversation-subject")
		conversationSubject.POST("/generate", HandleGenerateConversationSubject)

		conversationSummary := group.Group("/ai/conversation-summary")
		conversationSummary.POST("/generate", HandleGenerateConversationSummary)

		contactSummary := group.Group("/ai/contact-summary")
		contactSummary.POST("/generate", HandleGenerateContactSummary)

		conversationTags := group.Group("/ai/conversation-tags")
		conversationTags.POST("/generate", HandleGenerateConversationTags)

		// 回复助手只服务 PHP Bridge 的一次性生成请求，不直接暴露给访客端。
		replyPolish := group.Group("/ai/reply-polish")
		replyPolish.POST("/generate", HandleGenerateReplyPolish)

		if hub != nil {
			chat := group.Group("/ai/chat")
			chat.POST("/stream", handleChatStream(hub, nativeWorkers))
			chat.POST("/stop", handleChatStreamStop())
			return
		}

		// 缺少 hub 会让 AI 浮动框停在“等待响应”状态。
		// 在生产环境（program.go 主流程）这属于 wiring 错误，需要在启动阶段就让运维看到。
		// 单元测试里直接传 nil 合法：只会用到 providers 校验端点，/ai/chat/* 在路由上就不会出现。
		log.Printf("ai integration module: mercure hub is nil; /ai/chat endpoints disabled")
	}
}
