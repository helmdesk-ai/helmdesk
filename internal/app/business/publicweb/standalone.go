package publicweb

import (
	"bytes"
	"encoding/json"
	"fmt"
	"html/template"
	"log"
	"net/http"
	"regexp"
	"strings"

	"helmdesk/internal/app/config"
	"helmdesk/internal/app/phpbridge"
	"helmdesk/internal/app/webview"

	"github.com/gin-gonic/gin"
)

const (
	standaloneEntry  = "resources/js/standalone.ts"
	undeterminedLang = "und"

	bridgeAction = "App\\Actions\\Native\\Channel\\Web\\ResolvePublicWebChannelBootstrapBridgeAction"
)

var (
	// 渠道 code 约束：wch_ 前缀 + 小写数字字母。
	channelCodePattern = regexp.MustCompile(`^wch_[a-z0-9]+$`)
	langPattern        = regexp.MustCompile(`^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$`)

	standalonePageTemplate = template.Must(template.New("standalone-page").Parse(`<!DOCTYPE html>
<html lang="{{ .Lang }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ .Title }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <script>{{ .ThemePrescript }}</script>
    <style>html{background-color:oklch(1 0 0);}html.dark{background-color:oklch(0.145 0 0);}</style>
    <script>window.__HELMDESK_STANDALONE__={{ .BootstrapJSON }};</script>
    {{ .AssetTags }}
  </head>
  <body class="font-sans antialiased">
    <div id="app"></div>
  </body>
</html>`))

	resolveStandaloneBootstrap = func(cfg *config.Config, code string) (map[string]any, error) {
		result, err := phpbridge.CallNative(cfg.NativeWorkers, bridgeAction, code)
		if err != nil {
			return nil, err
		}
		return result.(map[string]any), nil
	}

	resolveStandaloneAssets = webview.ResolveAssets
)

type pageData struct {
	Lang           string
	Title          string
	ThemePrescript template.JS
	BootstrapJSON  template.JS
	AssetTags      template.HTML
}

// StandaloneHandler 渲染访客独立页的 HTML 壳，数据通过 Native bridge 从 Laravel 获取。
// URL 形如 /ch/{code}，code 是公开渠道唯一定位信息。
func StandaloneHandler(cfg *config.Config) gin.HandlerFunc {
	return func(c *gin.Context) {
		code := strings.TrimSpace(c.Param("code"))
		if !channelCodePattern.MatchString(code) {
			c.AbortWithStatus(http.StatusNotFound)
			return
		}

		channel, err := resolveStandaloneBootstrap(cfg, code)
		if err != nil {
			abortFromBridgeError(c, err, "resolve public standalone bootstrap")
			return
		}

		assetSet, err := resolveStandaloneAssets(cfg, standaloneEntry)
		if err != nil {
			log.Printf("resolve standalone assets failed: %v", err)
			c.AbortWithStatus(http.StatusInternalServerError)
			return
		}

		// json.Marshal 默认会把 <, >, & 转成 \u003c 等，让结果可以安全嵌进 <script> 标签并阻断基础 XSS。
		bootstrapJSON, err := json.Marshal(map[string]any{
			"channel":    channel,
			"user_token": readStandaloneUserToken(c),
		})
		if err != nil {
			log.Printf("marshal standalone bootstrap failed: %v", err)
			c.AbortWithStatus(http.StatusInternalServerError)
			return
		}
		title, err := pickTitle(channel)
		if err != nil {
			log.Printf("resolve standalone title failed: %v", err)
			c.AbortWithStatus(http.StatusInternalServerError)
			return
		}
		lang, err := pickLang(channel, c.GetHeader("Accept-Language"))
		if err != nil {
			log.Printf("resolve standalone lang failed: %v", err)
			c.AbortWithStatus(http.StatusInternalServerError)
			return
		}

		var buf bytes.Buffer
		if err := standalonePageTemplate.Execute(&buf, pageData{
			Lang:           lang,
			Title:          title,
			ThemePrescript: webview.ThemePrescript(),
			BootstrapJSON:  template.JS(bootstrapJSON),
			AssetTags:      webview.RenderTags(assetSet),
		}); err != nil {
			log.Printf("render standalone page failed: %v", err)
			c.AbortWithStatus(http.StatusInternalServerError)
			return
		}

		c.Data(http.StatusOK, "text/html; charset=utf-8", buf.Bytes())
	}
}

// readStandaloneUserToken 读取文档请求上注入的签名访客身份。
// 可设置请求头的容器通过 Authorization header 传入身份 token。
func readStandaloneUserToken(c *gin.Context) *string {
	header := strings.TrimSpace(c.GetHeader("Authorization"))
	if strings.HasPrefix(strings.ToLower(header), "bearer ") {
		token := strings.TrimSpace(header[len("Bearer "):])
		if token != "" {
			return &token
		}
	}

	return nil
}

// abortFromBridgeError 把 bridge 错误映射到 HTTP 响应：
//   - 4xx 业务异常（NotFoundHttpException 等）透传原状态码；
//   - 其它情况记日志并返回 500。
func abortFromBridgeError(c *gin.Context, err error, context string) {
	if bridgeErr := phpbridge.AsBridgeError(err); bridgeErr != nil && bridgeErr.IsClientError() {
		c.AbortWithStatus(bridgeErr.StatusCode)
		return
	}
	log.Printf("%s failed: %v", context, err)
	c.AbortWithStatus(http.StatusInternalServerError)
}

// pickTitle 从渠道配置中取站点名称作为页面标题，缺失时报错。
func pickTitle(channel map[string]any) (string, error) {
	if v, ok := channel["site_name"].(string); ok && strings.TrimSpace(v) != "" {
		return v, nil
	}

	return "", fmt.Errorf("standalone site_name is required")
}

// pickLang 选取页面 lang 属性：优先渠道配置的 locale，其次 Accept-Language，最后回退到 und。
func pickLang(channel map[string]any, acceptLanguage string) (string, error) {
	for _, key := range []string{"default_locale", "locale"} {
		if v, ok := channel[key].(string); ok {
			trimmed := strings.TrimSpace(v)
			if trimmed != "" && langPattern.MatchString(trimmed) {
				return trimmed, nil
			}
			if trimmed != "" {
				return "", fmt.Errorf("standalone %s is invalid: %s", key, trimmed)
			}
		}
	}

	if lang := firstAcceptedLanguage(acceptLanguage); lang != "" {
		return lang, nil
	}

	return undeterminedLang, nil
}

// firstAcceptedLanguage 解析 Accept-Language 头，返回首个符合语言标签正则的值。
func firstAcceptedLanguage(header string) string {
	for _, part := range strings.Split(header, ",") {
		lang := strings.TrimSpace(strings.SplitN(part, ";", 2)[0])
		if lang != "" && langPattern.MatchString(lang) {
			return lang
		}
	}

	return ""
}
