package reception

import (
	"net/http"
	"regexp"
	"strings"

	"github.com/gin-gonic/gin"
)

var sessionTokenPattern = regexp.MustCompile(`^[a-z0-9]{32}$`)

// readUserToken 从 Authorization: Bearer <token> 读取访客签名身份。
// 返回空串时表示请求中没有签名 token，PHP 端使用 session 身份。
func readUserToken(c *gin.Context) string {
	header := strings.TrimSpace(c.GetHeader("Authorization"))
	if strings.HasPrefix(strings.ToLower(header), "bearer ") {
		return strings.TrimSpace(header[len("Bearer "):])
	}

	return ""
}

// readVisitorToken 取访客会话 token：
//   - X-Helmdesk-Visitor-Token 优先（跨域 iframe / 原生 WebView 等 cookie 不可靠场景）
//   - 渠道会话 cookie 其次（同源浏览器场景）
//
// 返回空串时表示新访客，PHP 端会签发新的会话 token。
func readVisitorToken(c *gin.Context, code string) string {
	if header := normalizeSessionToken(c.GetHeader(headerVisitorToken)); header != "" {
		return header
	}

	return readSessionCookie(c, code)
}

// visitorEnvironment 从请求头中收集访客环境信息（语言、时区、国家、城市），供 PHP 端落库。
func visitorEnvironment(c *gin.Context) map[string]any {
	payload := map[string]any{}

	// 访客显式传入的语言优先。
	if locale := firstNonBlank(c.GetHeader("X-Helmdesk-Visitor-Locale"), firstAcceptedLanguage(c.GetHeader("Accept-Language"))); locale != "" {
		payload["locale"] = locale
	}
	if timezone := firstNonBlank(c.GetHeader("X-Helmdesk-Visitor-Timezone")); timezone != "" {
		payload["timezone"] = timezone
	}
	if country := firstNonBlank(c.GetHeader("CF-IPCountry"), c.GetHeader("X-Vercel-IP-Country"), c.GetHeader("X-Appengine-Country")); country != "" {
		payload["country"] = country
	}
	if city := firstNonBlank(c.GetHeader("CF-IPCity"), c.GetHeader("X-Vercel-IP-City"), c.GetHeader("X-Appengine-City")); city != "" {
		payload["city"] = city
	}

	return payload
}

// visitorClient 收集 Web 访客的行为与环境原始信号（UA、IP、当前页/来源/落地页、浏览器语言），
// 供 PHP 端落到会话渠道上下文与浏览轨迹。派生字段（浏览器/OS、地理）由 PHP Service 阶段补齐。
//
// current_url/referrer/landing_url/entry_url 由小部件 JS 通过 X-Helmdesk-Visitor-* 头透传；
// referrer 缺省时回退到浏览器自带的 Referer 头。
func visitorClient(c *gin.Context) map[string]any {
	payload := map[string]any{}

	if ua := strings.TrimSpace(c.Request.UserAgent()); ua != "" {
		payload["user_agent"] = ua
	}
	if ip := strings.TrimSpace(c.ClientIP()); ip != "" {
		payload["ip_address"] = ip
	}
	if lang := firstNonBlank(c.GetHeader("X-Helmdesk-Visitor-Locale"), firstAcceptedLanguage(c.GetHeader("Accept-Language"))); lang != "" {
		payload["browser_language"] = lang
	}
	if url := firstNonBlank(c.GetHeader(headerVisitorURL)); url != "" {
		payload["current_url"] = url
	}
	if referrer := firstNonBlank(c.GetHeader(headerVisitorReferrer), c.GetHeader("Referer")); referrer != "" {
		payload["referrer"] = referrer
	}
	if landing := firstNonBlank(c.GetHeader(headerVisitorLanding)); landing != "" {
		payload["landing_url"] = landing
	}
	if entry := firstNonBlank(c.GetHeader(headerVisitorEntry)); entry != "" {
		payload["entry_url"] = entry
	}

	return payload
}

// visitorEntryMode 根据请求头判断访客入口形态：widget 嵌入或独立页面。
func visitorEntryMode(c *gin.Context) string {
	if strings.EqualFold(strings.TrimSpace(c.GetHeader(headerEntryMode)), entryModeWidget) {
		return entryModeWidget
	}

	return entryModeStandalone
}

// firstNonBlank 返回参数列表中第一个非空白的字符串（已去除首尾空白）。
func firstNonBlank(values ...string) string {
	for _, value := range values {
		trimmed := strings.TrimSpace(value)
		if trimmed != "" {
			return trimmed
		}
	}
	return ""
}

// firstAcceptedLanguage 解析 Accept-Language 头，返回首个语言标签。
func firstAcceptedLanguage(value string) string {
	for _, part := range strings.Split(value, ",") {
		locale := strings.TrimSpace(strings.SplitN(part, ";", 2)[0])
		if locale != "" {
			return locale
		}
	}
	return ""
}

// readSessionCookie 读取指定渠道的访客会话 cookie 并校验格式，无效时返回空串。
func readSessionCookie(c *gin.Context, code string) string {
	raw, err := c.Cookie(cookieName(code))
	if err != nil {
		return ""
	}

	return normalizeSessionToken(raw)
}

// normalizeSessionToken 校验 token 是否符合预期格式，不合法时返回空串。
func normalizeSessionToken(raw string) string {
	trimmed := strings.TrimSpace(raw)
	if sessionTokenPattern.MatchString(trimmed) {
		return trimmed
	}

	return ""
}

// writeSessionCookieIfChanged 当会话 token 发生变化时写回 cookie，按入口形态选择 SameSite 策略。
func writeSessionCookieIfChanged(c *gin.Context, code, existing string, state map[string]any, entryMode string) {
	token, _ := state["session_token"].(string)
	if token == "" || len(token) != sessionTokenLength {
		return
	}
	if token == existing {
		return
	}

	secure := isRequestSecure(c)
	if entryMode == entryModeWidget && secure {
		c.SetSameSite(http.SameSiteNoneMode)
	} else {
		c.SetSameSite(http.SameSiteLaxMode)
	}
	c.SetCookie(cookieName(code), token, cookieMaxAgeSec, "/", "", secure, true)
}

// isRequestSecure 判断当前请求是否走 HTTPS，兼顾直连 TLS 和反代下的 X-Forwarded-Proto。
func isRequestSecure(c *gin.Context) bool {
	if c.Request.TLS != nil {
		return true
	}
	return strings.EqualFold(strings.TrimSpace(c.GetHeader("X-Forwarded-Proto")), "https")
}

// cookieName 按渠道 code 生成访客会话 cookie 名称。
func cookieName(code string) string {
	return cookiePrefix + code
}
