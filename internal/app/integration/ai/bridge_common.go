package ai

import "strings"

// missingBridgeProviderCredentials 返回必填但缺失的供应商凭据字段。
func missingBridgeProviderCredentials(provider BridgeProvider) []string {
	missing := make([]string, 0)
	for _, field := range provider.CredentialFields {
		if !field.Required {
			continue
		}
		if strings.TrimSpace(provider.Credentials[field.Field]) == "" {
			missing = append(missing, field.Field)
		}
	}
	return missing
}

// limitRunes 限制提示词片段长度，避免多字节字符被截断。
func limitRunes(value string, limit int) string {
	runes := []rune(value)
	if len(runes) <= limit {
		return value
	}

	return string(runes[:limit])
}
