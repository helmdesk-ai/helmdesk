// realtime 包提供发布 Mercure 更新所需的内部接口。
package realtime

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"github.com/dunglas/mercure"
	"github.com/gin-gonic/gin"
)

type publishRequest struct {
	Topics []string `json:"topics"`
	Type   string   `json:"type"`
	Data   any      `json:"data"`
}

// Module 返回向指定路由组注册实时发布接口的注册函数。
func Module(hub *mercure.Hub) func(*gin.RouterGroup) {
	return func(group *gin.RouterGroup) {
		group.POST("/realtime/publish", publishHandler(hub))
	}
}

// publishHandler 校验请求并把事件发布到 Mercure Hub 的指定 topics。
func publishHandler(hub *mercure.Hub) gin.HandlerFunc {
	return func(c *gin.Context) {
		if hub == nil {
			c.JSON(http.StatusServiceUnavailable, gin.H{
				"success": false,
				"message": "mercure hub is not initialized",
			})
			return
		}

		var req publishRequest
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{
				"success": false,
				"message": "invalid publish payload",
			})
			return
		}

		topics := normalizeTopics(req.Topics)
		if len(topics) == 0 {
			c.JSON(http.StatusBadRequest, gin.H{
				"success": false,
				"message": "topics are required",
			})
			return
		}

		eventType := strings.TrimSpace(req.Type)
		if eventType == "" {
			eventType = "message"
		}

		data, err := json.Marshal(req.Data)
		if err != nil {
			c.JSON(http.StatusBadRequest, gin.H{
				"success": false,
				"message": "data must be json serializable",
			})
			return
		}

		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()

		if err := hub.Publish(ctx, &mercure.Update{
			Topics: topics,
			Event: mercure.Event{
				Data: string(data),
				Type: eventType,
			},
		}); err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{
				"success": false,
				"message": "publish failed",
			})
			return
		}

		c.JSON(http.StatusOK, gin.H{"success": true})
	}
}

// normalizeTopics 去重并裁剪空白 topic，保留原始顺序。
func normalizeTopics(values []string) []string {
	topics := make([]string, 0, len(values))
	seen := map[string]struct{}{}

	for _, value := range values {
		topic := strings.TrimSpace(value)
		if topic == "" {
			continue
		}
		if _, exists := seen[topic]; exists {
			continue
		}
		seen[topic] = struct{}{}
		topics = append(topics, topic)
	}

	return topics
}
