package ai

import (
	"net/http"

	"github.com/gin-gonic/gin"
)

// HandleValidate 是 Gin 路由处理器，绑定 BridgeRequest 后调用 validate 完成 provider/model 配置校验。
func HandleValidate(c *gin.Context) {
	var request BridgeRequest
	if err := c.ShouldBindJSON(&request); err != nil {
		c.JSON(http.StatusUnprocessableEntity, BridgeResponse{
			Success:   false,
			Supported: false,
			Code:      CodeRequestInvalidPayload,
			Params:    map[string]any{"error": err.Error()},
			Message:   err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, validate(c.Request.Context(), request))
}

// HandleCheck 是 Gin 路由处理器，绑定 BridgeRequest 后调用 check 对 provider 做一次真实连通性测试。
func HandleCheck(c *gin.Context) {
	var request BridgeRequest
	if err := c.ShouldBindJSON(&request); err != nil {
		c.JSON(http.StatusUnprocessableEntity, BridgeResponse{
			Success:   false,
			Supported: false,
			Code:      CodeRequestInvalidPayload,
			Params:    map[string]any{"error": err.Error()},
			Message:   err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, check(c.Request.Context(), request))
}
