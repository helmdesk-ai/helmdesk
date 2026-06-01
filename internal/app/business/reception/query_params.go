package reception

import (
	"encoding/json"
	"regexp"
	"strings"

	"github.com/gin-gonic/gin"
)

var visitorQueryParamPattern = regexp.MustCompile(`^[a-zA-Z0-9_.-]{1,64}$`)

// 单次访客请求允许透传给 PHP 的最大 query 参数数量，限定桥接侧的内存上限。
const maxQueryParamCount = 32

// 单个 query 参数值最大长度，超过截断。
const maxQueryParamValueLength = 1024

// collectVisitorQueryParams 读取访客业务自定义 query 参数：
//   - 独立页由前端把页面 URL 的业务参数收敛进该 header；widget 由宿主页显式配置经 postMessage 透传。
//   - header 是接待端点读取业务参数的唯一来源。
//   - 保留 key 归接待协议使用，不进入业务参数映射。
//   - 总大小受 maxQueryParamCount / maxQueryParamValueLength 限制。
func collectVisitorQueryParams(c *gin.Context) map[string]string {
	params := readVisitorQueryParamsHeader(c)
	if params == nil {
		return map[string]string{}
	}

	return params
}

// 保留 key：Helmdesk 公开接口自己使用的参数名，与渠道参数映射保持隔离。
var visitorQueryParamBlacklist = map[string]struct{}{
	"user_token":    {},
	"session_token": {},
	"_token":        {},
	"signature":     {},
	"sig":           {},
}

// acceptVisitorQueryParamKey 判断 query key 是否符合命名规则且不在黑名单内。
func acceptVisitorQueryParamKey(key string) bool {
	trimmed := strings.TrimSpace(key)
	if !visitorQueryParamPattern.MatchString(trimmed) {
		return false
	}
	if _, blacklisted := visitorQueryParamBlacklist[strings.ToLower(trimmed)]; blacklisted {
		return false
	}
	return true
}

// clampQueryParamValue 去除首尾空白并截断超长 query 参数值。
func clampQueryParamValue(value string) string {
	trimmed := strings.TrimSpace(value)
	if len(trimmed) > maxQueryParamValueLength {
		return trimmed[:maxQueryParamValueLength]
	}
	return trimmed
}

// normalizeQueryParamMap 过滤、清洗并按数量上限收敛访客 query 参数。
func normalizeQueryParamMap(values map[string]string) map[string]string {
	if len(values) == 0 {
		return nil
	}
	out := make(map[string]string, len(values))
	for key, value := range values {
		if !acceptVisitorQueryParamKey(key) {
			continue
		}
		clean := clampQueryParamValue(value)
		if clean == "" {
			continue
		}
		out[key] = clean
		if len(out) >= maxQueryParamCount {
			break
		}
	}
	return out
}

// readVisitorQueryParamsHeader 解析 widget 宿主页通过自定义头透传的 query 参数 JSON。
func readVisitorQueryParamsHeader(c *gin.Context) map[string]string {
	raw := strings.TrimSpace(c.GetHeader(headerQueryParams))
	if raw == "" || len(raw) > 8192 {
		return nil
	}

	var decoded map[string]string
	if err := json.Unmarshal([]byte(raw), &decoded); err != nil {
		return nil
	}

	return normalizeQueryParamMap(decoded)
}
