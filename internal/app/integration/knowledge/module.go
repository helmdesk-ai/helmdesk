package knowledge

import (
	"context"
	"net/http"

	"github.com/gin-gonic/gin"
)

// Module 把知识库相关的内部桥接路由挂到 BridgeModule 框架下。
//
// 端点：
//   - POST /knowledge/embed      : 批量调嵌入模型，返回 dim + 向量
//   - POST /knowledge/summarize  : RAPTOR 单层摘要
//   - POST /knowledge/rerank     : 调远程 rerank 模型给 hits 重排（暂仅支持 cohere 兼容协议）
//
// 文档解析与分块完全在 PHP 端完成（参见 App\Services\KnowledgeBase\Parsing），
// 这里只承接需要走 Eino / Go 协程的远程模型调用。
func Module() func(*gin.RouterGroup) {
	return func(group *gin.RouterGroup) {
		kb := group.Group("/knowledge")
		kb.POST("/embed", handleEmbed)
		kb.POST("/summarize", handleSummarize)
		kb.POST("/rerank", handleRerank)
	}
}

// handleEmbed 处理 POST /knowledge/embed：解析请求 payload 后调用 embedContents 返回向量结果，
// payload 不合法时回 422 并带上结构化错误码。
func handleEmbed(c *gin.Context) {
	bindKnowledgeRequest(c, embedContents)
}

// handleSummarize 处理 POST /knowledge/summarize：解析请求后委托给 summarizeBatches 做 RAPTOR 单层摘要，
// payload 不合法时回 422 并带上结构化错误码。
func handleSummarize(c *gin.Context) {
	bindKnowledgeRequest(c, summarizeBatches)
}

// handleRerank 处理 POST /knowledge/rerank：解析请求后调用 rerankContents 走远程 rerank 模型，
// payload 不合法时回 422 并带上结构化错误码。
func handleRerank(c *gin.Context) {
	bindKnowledgeRequest(c, rerankContents)
}

// bindKnowledgeRequest 把"绑定 JSON → 执行业务 → 序列化响应"的样板集中到一处。
// payload 不合法时回 422 并附结构化错误码，业务结果一律按 200 + 自定义 success 字段返回。
// Req/Resp 用泛型暴露，让每个 handler 直接复用各自的 Request / Response 类型而保持调用点纯粹。
func bindKnowledgeRequest[Req any, Resp any](c *gin.Context, run func(ctx context.Context, req Req) Resp) {
	var req Req
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, errorResponse{
			Success: false,
			Code:    codeRequestInvalidPayload,
			Params:  map[string]any{"error": err.Error()},
			Message: err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, run(c.Request.Context(), req))
}
