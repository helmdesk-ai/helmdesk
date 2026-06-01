package payload

import (
	"sort"
	"strconv"
)

// Normalize 规范化 PHP 桥接返回的会话快照，主要把 messages 字段统一成数组。
func Normalize(payload map[string]any) {
	payload["messages"] = normalizeMessages(payload["messages"])
}

// inboxStatusTeammatePending 和 inboxStatusTeammateHandling 对应 PHP ConversationInboxStatus 枚举值。
const (
	inboxStatusTeammatePending  = "teammate_pending"
	inboxStatusTeammateHandling = "teammate_handling"
)

// ShouldSkipPlaceholderAI 判断当前会话是否已被人工接管，从而跳过占位 AI 回复。
func ShouldSkipPlaceholderAI(payload map[string]any) bool {
	inboxStatus, _ := payload["inbox_status"].(string)
	return inboxStatus == inboxStatusTeammatePending || inboxStatus == inboxStatusTeammateHandling
}

// StripInternalStateFields 移除仅供 Go 端决策使用的内部状态字段，保证前端响应里只剩业务字段。
func StripInternalStateFields(payload map[string]any) {
	delete(payload, "inbox_status")
}

// normalizeMessages 把 PHP 返回的 messages 字段统一成切片。
func normalizeMessages(value any) any {
	switch messages := value.(type) {
	case nil:
		return []any{}
	case []any:
		return messages
	case map[string]any:
		if len(messages) == 0 {
			return []any{}
		}
		if slice, ok := numericMapToSlice(messages); ok {
			return slice
		}
	}

	return value
}

// numericMapToSlice 当 map 的键是连续 0..n-1 整数时还原为切片，否则返回 false。
func numericMapToSlice(values map[string]any) ([]any, bool) {
	indexes := make([]int, 0, len(values))
	byIndex := make(map[int]any, len(values))

	for key, value := range values {
		index, err := strconv.Atoi(key)
		if err != nil || index < 0 {
			return nil, false
		}

		indexes = append(indexes, index)
		byIndex[index] = value
	}

	sort.Ints(indexes)
	for expected, index := range indexes {
		if index != expected {
			return nil, false
		}
	}

	// 上面已经断言 indexes 是 [0, 1, ..., n-1]；这里直接按下标取值即可。
	messages := make([]any, len(indexes))
	for i := range messages {
		messages[i] = byIndex[i]
	}

	return messages, true
}
