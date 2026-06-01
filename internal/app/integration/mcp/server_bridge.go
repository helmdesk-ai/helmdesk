package mcp

import (
	"context"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
)

// Module 注册 MCP 业务在内部桥接路由组下的所有端点。
// 通过 BridgeModule 形态暴露，交由 routes.RegisterInternalBridge 统一挂载。
func Module() func(*gin.RouterGroup) {
	return func(group *gin.RouterGroup) {
		servers := group.Group("/mcp/servers")
		servers.POST("/validate", handleValidate())
		servers.POST("/check", handleCheck())
		servers.POST("/list-tools", handleListTools())
	}
}

// dialServer 为单次桥接请求新建一个 mcp-go streamable_http 客户端。
//
// mcp-go 客户端是有状态的（持有 session id 与读 goroutine）。每次桥接请求都开一个新的、
// 用完立即 Close，让每次会话拥有独立的 session id 与读写循环。
func dialServer(server BridgeServer) (*Client, error) {
	return NewStreamableHTTPClient(server, ResolveTimeout(server.TimeoutSeconds))
}

// bindBridgeRequest 把"绑定 JSON + endpoint/transport 兜底校验"的样板集中到一处，供三个端点共用。
// 返回 ok=true 时 BridgeRequest 已通过 preflight，可直接进入业务流程；
// 返回 ok=false 时 HTTP 响应已经写回（422 解析失败或 200 + success=false），调用方应直接 return。
func bindBridgeRequest(c *gin.Context) (BridgeRequest, bool) {
	var req BridgeRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, BridgeResponse{
			Success:   false,
			Supported: false,
			Code:      CodeRequestInvalidPayload,
			Params:    map[string]any{"error": err.Error()},
			Message:   err.Error(),
		})
		return BridgeRequest{}, false
	}

	if resp, ok := preflightValidate(req); !ok {
		c.JSON(http.StatusOK, resp)
		return BridgeRequest{}, false
	}

	return req, true
}

// handleValidate 只做轻量字段校验：endpoint 必填、transport 支持。
// 实际连接交给 check / list-tools 端点，让保存配置阶段始终保持本地决策、迅速返回。
func handleValidate() gin.HandlerFunc {
	return func(c *gin.Context) {
		if _, ok := bindBridgeRequest(c); !ok {
			return
		}

		c.JSON(http.StatusOK, BridgeResponse{
			Success:   true,
			Supported: true,
			Code:      CodeValidateSucceeded,
			Message:   "mcp server configuration accepted",
		})
	}
}

// handleCheck 完成一次 initialize 握手作为连接测试。
func handleCheck() gin.HandlerFunc {
	return func(c *gin.Context) {
		req, ok := bindBridgeRequest(c)
		if !ok {
			return
		}

		client, err := dialServer(req.Server)
		if err != nil {
			c.JSON(http.StatusOK, mapCheckError(err))
			return
		}
		defer client.Close()

		ctx, cancel := context.WithTimeout(c.Request.Context(), ResolveTimeout(req.Server.TimeoutSeconds))
		defer cancel()

		if err := client.Initialize(ctx); err != nil {
			c.JSON(http.StatusOK, mapCheckError(err))
			return
		}

		c.JSON(http.StatusOK, BridgeResponse{
			Success:   true,
			Supported: true,
			Code:      CodeCheckSucceeded,
			Message:   "mcp connectivity check passed",
		})
	}
}

// handleListTools 拉取远端工具列表并返回。
func handleListTools() gin.HandlerFunc {
	return func(c *gin.Context) {
		req, ok := bindBridgeRequest(c)
		if !ok {
			return
		}

		client, err := dialServer(req.Server)
		if err != nil {
			c.JSON(http.StatusOK, listToolsFailure(err))
			return
		}
		defer client.Close()

		ctx, cancel := context.WithTimeout(c.Request.Context(), ResolveTimeout(req.Server.TimeoutSeconds))
		defer cancel()

		if err := client.Initialize(ctx); err != nil {
			c.JSON(http.StatusOK, listToolsFailure(err))
			return
		}

		tools, err := client.ListTools(ctx)
		if err != nil {
			c.JSON(http.StatusOK, listToolsFailure(err))
			return
		}

		c.JSON(http.StatusOK, BridgeResponse{
			Success:   true,
			Supported: true,
			Code:      CodeListToolsSucceeded,
			Message:   "tools listed",
			Tools:     tools,
		})
	}
}

// preflightValidate 把 endpoint / transport 校验复用到 validate / check / list-tools。
// 要求 transport 必填且只接受 streamable_http；PHP 侧已经枚举强约束，这里再卡一遍兜底。
func preflightValidate(req BridgeRequest) (BridgeResponse, bool) {
	if strings.TrimSpace(req.Server.EndpointURL) == "" {
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeValidateMissingEndpoint,
			Message:   "endpoint URL is required",
		}, false
	}

	if req.Server.Transport != "streamable_http" {
		return BridgeResponse{
			Success:   false,
			Supported: false,
			Code:      CodeValidateUnsupportedTx,
			Params:    map[string]any{"transport": req.Server.Transport},
			Message:   "unsupported transport: " + req.Server.Transport,
		}, false
	}

	return BridgeResponse{}, true
}

// listToolsFailure 把任意错误包成 list-tools 的统一失败响应。
func listToolsFailure(err error) BridgeResponse {
	return BridgeResponse{
		Success:   false,
		Supported: true,
		Code:      CodeListToolsFailed,
		Params:    map[string]any{"error": err.Error()},
		Message:   err.Error(),
	}
}

// mapCheckError 把客户端错误归类成稳定 code，用于 check 端点。
func mapCheckError(err error) BridgeResponse {
	switch {
	case errors.Is(err, ErrTimeout):
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeCheckTimeout,
			Message:   err.Error(),
		}
	case errors.Is(err, ErrUnauthorized):
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeCheckUnauthorized,
			Message:   err.Error(),
		}
	case errors.Is(err, ErrProtocol):
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeCheckProtocolError,
			Params:    map[string]any{"error": err.Error()},
			Message:   err.Error(),
		}
	default:
		return BridgeResponse{
			Success:   false,
			Supported: true,
			Code:      CodeCheckFailed,
			Params:    map[string]any{"error": err.Error()},
			Message:   err.Error(),
		}
	}
}

// ResolveTimeout 给定秒数算出实际请求超时；0/负值回退到默认 30s，超过 120s 截断。
// 公开出来给其它包（如 ai/tools）共用同一份界限。
func ResolveTimeout(seconds int) time.Duration {
	if seconds <= 0 {
		return defaultTimeout
	}
	if seconds > 120 {
		return 120 * time.Second
	}
	return time.Duration(seconds) * time.Second
}
