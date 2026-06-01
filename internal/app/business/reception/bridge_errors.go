package reception

import (
	"helmdesk/internal/app/phpbridge"
	"log"
	"net/http"

	"github.com/gin-gonic/gin"
)

// abortFromBridgeError 把 PHP 桥接错误转成 HTTP 响应：业务异常透传 4xx，其它记日志并返回 500。
func abortFromBridgeError(c *gin.Context, err error, context string) {
	if bridgeErr := phpbridge.AsBridgeError(err); bridgeErr != nil && bridgeErr.IsClientError() {
		c.AbortWithStatusJSON(bridgeErr.StatusCode, gin.H{"message": bridgeErr.Message})
		return
	}
	log.Printf("%s failed: %v", context, err)
	c.AbortWithStatus(http.StatusInternalServerError)
}
