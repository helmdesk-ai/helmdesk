package routes

import (
	"crypto/subtle"
	"net/http"

	"github.com/gin-gonic/gin"
)

// BridgeTokenHeader 是 PHP 回调 Go loopback 时携带的鉴权 header 名。
// PHP 侧 app/Services/GoBridge/GoBridgeClient.php 用同名字面量，双方靠约定对齐。
const BridgeTokenHeader = "X-Helmdesk-Bridge-Token"

// requireBridgeToken 校验请求携带的 bridge token。
// token 缺失、不匹配，或服务端 expected 为空（代表尚未初始化 token）时，
// 都会短路返回 401 并终止后续处理，让 misconfiguration 在第一时间被发现。
// 用 subtle.ConstantTimeCompare 替代 != 做比较，让本机 / 同容器内的旁路攻击者
// 无法借助响应耗时差异逐字节恢复 token。
func requireBridgeToken(expected string) gin.HandlerFunc {
	expectedBytes := []byte(expected)
	return func(c *gin.Context) {
		got := []byte(c.GetHeader(BridgeTokenHeader))
		if len(expectedBytes) == 0 || subtle.ConstantTimeCompare(got, expectedBytes) != 1 {
			c.AbortWithStatus(http.StatusUnauthorized)
			return
		}

		c.Next()
	}
}
