package logging

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode"
)

// ANSI 色码集中定义，仅在 color 开启（stderr 为 TTY 或显式 LOG_COLOR）时拼接。
const (
	ansiReset   = "\x1b[0m"
	ansiBold    = "\x1b[1m"
	ansiFaint   = "\x1b[2m"
	ansiRed     = "\x1b[31m"
	ansiGreen   = "\x1b[32m"
	ansiYellow  = "\x1b[33m"
	ansiMagenta = "\x1b[35m"
	ansiCyan    = "\x1b[36m"
)

// textHandler 渲染开发态文本日志，字段顺序为 ts level msg service、结构化属性。
//
// 与生产 JSON（service / ts / level / msg / 属性）有意不一致：开发态以人眼扫读为先，
// 把 msg 紧跟级别提前，便于第一眼定位本条日志在做什么；生产仍走 JSON handler 保证机器解析口径。
type textHandler struct {
	w           io.Writer
	level       slog.Level
	color       bool
	attrs       []slog.Attr
	groupPrefix string
	mu          *sync.Mutex
}

// newTextHandler 构造统一文本日志 handler，避免 Go 默认 TextHandler 与 Laravel 文本 formatter 字段顺序不一致。
func newTextHandler(w io.Writer, level slog.Level, color bool) slog.Handler {
	return &textHandler{
		w:     w,
		level: level,
		color: color,
		mu:    &sync.Mutex{},
	}
}

// Enabled 判断当前日志级别是否应输出。
func (h *textHandler) Enabled(_ context.Context, level slog.Level) bool {
	return level >= h.level
}

// Handle 渲染单条日志。
func (h *textHandler) Handle(_ context.Context, record slog.Record) error {
	attrs := h.recordAttrs(record)
	service := h.extractService(attrs)

	parts := make([]string, 0, len(attrs)+4)
	parts = append(parts,
		h.formatTimestamp(record.Time),
		h.formatLevel(record.Level),
		h.formatMessage(record.Message),
		h.formatService(service),
	)

	for _, attr := range attrs {
		attr.Value = attr.Value.Resolve()
		if attr.Key == "" || attr.Key == "service" {
			continue
		}

		parts = append(parts, h.formatAttr(h.groupPrefix+attr.Key, attrValueText(attr.Value)))
	}

	line := strings.Join(parts, " ") + "\n"

	h.mu.Lock()
	defer h.mu.Unlock()
	_, err := io.WriteString(h.w, line)

	return err
}

// WithAttrs 返回携带预绑定属性的新 handler。
func (h *textHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	next := h.clone()
	next.attrs = append(next.attrs, attrs...)

	return next
}

// WithGroup 返回带 group 前缀的新 handler。
func (h *textHandler) WithGroup(name string) slog.Handler {
	if name == "" {
		return h
	}

	next := h.clone()
	next.groupPrefix += name + "."

	return next
}

// clone 复制 handler 配置，并共享写锁。
func (h *textHandler) clone() *textHandler {
	attrs := make([]slog.Attr, len(h.attrs))
	copy(attrs, h.attrs)

	return &textHandler{
		w:           h.w,
		level:       h.level,
		color:       h.color,
		attrs:       attrs,
		groupPrefix: h.groupPrefix,
		mu:          h.mu,
	}
}

// recordAttrs 合并预绑定属性与本条日志属性。
func (h *textHandler) recordAttrs(record slog.Record) []slog.Attr {
	attrs := make([]slog.Attr, 0, len(h.attrs)+record.NumAttrs())
	attrs = append(attrs, h.attrs...)
	record.Attrs(func(attr slog.Attr) bool {
		attrs = append(attrs, attr)

		return true
	})

	return attrs
}

// extractService 从属性里提取 service，用于固定放在核心字段区域。
func (h *textHandler) extractService(attrs []slog.Attr) string {
	for _, attr := range attrs {
		if attr.Key == "service" {
			return attr.Value.Resolve().String()
		}
	}

	return ServiceName
}

// formatTimestamp 渲染 ts 字段；时间戳属于扫读噪声，开发态整体调暗，让视线越过它落到 level / msg。
func (h *textHandler) formatTimestamp(t time.Time) string {
	field := "ts=" + t.UTC().Format(time.RFC3339Nano)
	if !h.color {
		return field
	}

	return ansiFaint + field + ansiReset
}

// formatLevel 渲染 level 字段：key 调暗、value 按级别上色并加粗，同时右补空格定宽，使后续字段成列对齐。
func (h *textHandler) formatLevel(level slog.Level) string {
	text := fmt.Sprintf("%-5s", level.String())
	if !h.color {
		return "level=" + text
	}

	return ansiFaint + "level=" + ansiReset + levelColor(level) + ansiBold + text + ansiReset
}

// levelColor 返回级别对应的 ANSI 前景色：error 红、warn 黄、debug 青、info 绿。
func levelColor(level slog.Level) string {
	switch {
	case level >= slog.LevelError:
		return ansiRed
	case level >= slog.LevelWarn:
		return ansiYellow
	case level <= slog.LevelDebug:
		return ansiCyan
	default:
		return ansiGreen
	}
}

// formatService 渲染 service 字段：key 调暗、value 用洋红，便于在合流日志里区分 go 与 laravel 来源。
func (h *textHandler) formatService(service string) string {
	value := quoteLogfmtValue(service)
	if !h.color {
		return "service=" + value
	}

	return ansiFaint + "service=" + ansiReset + ansiMagenta + value + ansiReset
}

// formatAttr 渲染普通结构化属性：key 调暗、value 保持正常前景色，让等号右侧的实际数据成为视觉重点。
func (h *textHandler) formatAttr(key, value string) string {
	rendered := quoteLogfmtValue(value)
	if !h.color {
		return key + "=" + rendered
	}

	return ansiFaint + key + "=" + ansiReset + rendered
}

// formatMessage 渲染 msg 字段：key 调暗、value 加粗，作为一行日志里第一眼应定位到的主信息。
func (h *textHandler) formatMessage(message string) string {
	value := quoteLogfmtValue(message)
	if !h.color {
		return "msg=" + value
	}

	return ansiFaint + "msg=" + ansiReset + ansiBold + value + ansiReset
}

// attrValueText 把 slog.Value 渲染为 logfmt 值。
func attrValueText(value slog.Value) string {
	switch value.Kind() {
	case slog.KindString:
		return value.String()
	case slog.KindTime:
		return value.Time().UTC().Format(time.RFC3339Nano)
	case slog.KindDuration:
		return value.Duration().String()
	case slog.KindBool:
		return strconv.FormatBool(value.Bool())
	case slog.KindInt64:
		return strconv.FormatInt(value.Int64(), 10)
	case slog.KindUint64:
		return strconv.FormatUint(value.Uint64(), 10)
	case slog.KindFloat64:
		return strconv.FormatFloat(value.Float64(), 'g', -1, 64)
	case slog.KindGroup:
		return fmt.Sprint(value.Any())
	default:
		return fmt.Sprint(value.Any())
	}
}

// quoteLogfmtValue 按 logfmt 习惯只在必要时加引号。
func quoteLogfmtValue(value string) string {
	if value == "" {
		return `""`
	}

	for _, r := range value {
		if unicode.IsSpace(r) || r == '"' || r == '\\' || r == '=' || unicode.IsControl(r) {
			return strconv.Quote(value)
		}
	}

	return value
}
