// attachment 包负责附件的 Go 端直出：通过 HMAC 签名验证后，绕过 PHP 直接吐 storage 文件。
package attachment

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"helmdesk/internal/app/config"

	"github.com/gin-gonic/gin"
)

// RegisterRoutes 把签名附件下载接口挂载到主路由上。
func RegisterRoutes(router *gin.Engine, cfg *config.Config) {
	router.GET("/attachments/dl", downloadHandler(cfg))
}

// 处理附件自包含签名下载请求：验证 HMAC 签名后直接从本地 storage 输出文件。
func downloadHandler(cfg *config.Config) gin.HandlerFunc {
	safeRoot, err := filepath.Abs(filepath.Join(cfg.StoragePath, "app", "private"))
	if err != nil {
		log.Fatalf("无法解析附件存储根目录: %v", err)
	}

	return func(c *gin.Context) {
		key := c.Query("key")
		mime := c.Query("mime")
		name := c.Query("name")
		expiresStr := c.Query("expires")
		sig := c.Query("sig")

		if key == "" || mime == "" || name == "" || expiresStr == "" || sig == "" {
			c.AbortWithStatus(http.StatusBadRequest)
			return
		}

		exp, err := strconv.ParseInt(expiresStr, 10, 64)
		if err != nil || time.Now().Unix() > exp {
			c.AbortWithStatus(http.StatusGone)
			return
		}

		if !verifySignature(cfg.AppKey, key, mime, name, expiresStr, sig) {
			c.AbortWithStatus(http.StatusForbidden)
			return
		}

		filePath, err := resolveSafePath(safeRoot, key)
		if err != nil {
			c.AbortWithStatus(http.StatusBadRequest)
			return
		}

		if remaining := exp - time.Now().Unix(); remaining > 0 {
			c.Header("Cache-Control", fmt.Sprintf("private, max-age=%d, immutable", remaining))
			c.Header("Expires", time.Unix(exp, 0).UTC().Format(http.TimeFormat))
		}

		c.Header("Content-Type", mime)
		c.Header("X-Content-Type-Options", "nosniff")
		if needsScriptSandbox(mime) {
			c.Header("Content-Security-Policy", "sandbox")
		}
		c.Header("Content-Disposition", contentDisposition(mime, name))

		c.File(filePath)
	}
}

// 用与 PHP 端一致的 length-prefix payload 校验 HMAC 签名。
func verifySignature(appKey []byte, key, mime, name, expires, sig string) bool {
	payload := fmt.Sprintf("v1|%d:%s|%d:%s|%d:%s|%s",
		len(key), key, len(mime), mime, len(name), name, expires)

	mac := hmac.New(sha256.New, appKey)
	mac.Write([]byte(payload))
	expected := hex.EncodeToString(mac.Sum(nil))

	return hmac.Equal([]byte(sig), []byte(expected))
}

// 把传入 key 锁定到 safeRoot 内，确保最终路径始终位于附件根目录之下。
func resolveSafePath(safeRoot, key string) (string, error) {
	cleaned := filepath.Clean("/" + key)
	abs, err := filepath.Abs(filepath.Join(safeRoot, cleaned))
	if err != nil {
		return "", err
	}
	if abs != safeRoot && !strings.HasPrefix(abs, safeRoot+string(os.PathSeparator)) {
		return "", errors.New("path escapes storage root")
	}
	return abs, nil
}

// 仅对会被浏览器作为"文档"解析、可能触发脚本执行的格式启用 CSP sandbox；
// 光栅图 / 视频 / 音频等媒体类型保持原始响应，让 Chromium 直接走 inline 预览。
func needsScriptSandbox(mime string) bool {
	lower := strings.ToLower(mime)
	switch {
	case strings.HasPrefix(lower, "image/svg"),
		strings.HasPrefix(lower, "text/html"),
		strings.HasPrefix(lower, "application/xhtml"),
		strings.HasSuffix(lower, "+xml"):
		return true
	}
	return false
}

// 图片走 inline 预览，其它文件走 attachment 下载；filename 用 RFC 5987 编码。
func contentDisposition(mime, name string) string {
	disposition := "attachment"
	if strings.HasPrefix(mime, "image/") {
		disposition = "inline"
	}
	return fmt.Sprintf("%s; filename*=UTF-8''%s", disposition, url.PathEscape(name))
}
