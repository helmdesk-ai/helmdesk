// Package logging 统一 Go 侧的结构化日志输出，作为 Go + Laravel 共同日志地基的一半。
//
// 设计目标：Go 与 Laravel 吐出同一套 JSON schema 到 stderr（service / ts / level / msg /
// request_id / trace_id / tenant_id），由进程外的 supervisor（journald / docker / 采集 agent）
// 汇聚到一处。Go 与 PHP 同处一个 FrankenPHP 进程，因此只要两侧都写 stderr 即天然合流。
package logging

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"os"
	"runtime/debug"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
)

// ServiceName 是本进程 Go 侧日志的 service 标识，与 Laravel 侧的 "laravel" 区分。
const ServiceName = "go"

// Setup 按 LOG_LEVEL / LOG_FORMAT 构建写往 stderr 的 slog.Logger 并设为默认。
//
// 设为默认后，标准库 log 包（log.Printf / log.Fatalf 等存量调用）也会经由该 handler
// 转成统一格式，因此无需逐处改写既有调用即可获得一致输出。新代码应直接用 slog 携带结构化属性。
func Setup() {
	logger := slog.New(newHandler(os.Stderr, levelFromEnv()))
	slog.SetDefault(logger)
}

// newHandler 构造统一 schema 的 handler：时间字段重命名为 ts、钉死 UTC，并固定注入 service 基础属性。
func newHandler(w io.Writer, level slog.Level) slog.Handler {
	options := &slog.HandlerOptions{
		Level: level,
		ReplaceAttr: func(groups []string, a slog.Attr) slog.Attr {
			// 与 Laravel 侧对齐：时间键统一为 ts，并钉死 UTC，
			// 不依赖主机 TZ，保证多实例集中后时间戳口径一致。
			if a.Key == slog.TimeKey && len(groups) == 0 {
				a.Key = "ts"
				a.Value = slog.TimeValue(a.Value.Time().UTC())
			}
			return a
		},
	}
	var handler slog.Handler
	if logFormatFromEnv() == "text" {
		handler = newTextHandler(w, level, shouldColorizeText(w))
	} else {
		handler = slog.NewJSONHandler(w, options)
	}
	return handler.WithAttrs([]slog.Attr{slog.String("service", ServiceName)})
}

// logFormatFromEnv 解析日志编码格式：生产默认 JSON，本地可显式设 LOG_FORMAT=text。
func logFormatFromEnv() string {
	switch strings.ToLower(strings.TrimSpace(os.Getenv("LOG_FORMAT"))) {
	case "text", "console", "terminal":
		return "text"
	default:
		return "json"
	}
}

// levelFromEnv 解析 LOG_LEVEL 环境变量，与 Laravel 的 LOG_LEVEL 取值习惯保持一致，默认 info。
func levelFromEnv() slog.Level {
	switch strings.ToLower(strings.TrimSpace(os.Getenv("LOG_LEVEL"))) {
	case "debug":
		return slog.LevelDebug
	case "warning", "warn":
		return slog.LevelWarn
	case "error", "critical", "alert", "emergency":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}

// shouldColorizeText 判断开发文本日志是否输出 ANSI 颜色。
//
// 终端不会自动识别 logfmt/key=value 并上色；需要日志显式输出 ANSI 色码，或交给外部工具处理。
// 因此默认 auto：仅 stderr 是 TTY 时上色，管道、文件和采集器里保持纯文本。
func shouldColorizeText(w io.Writer) bool {
	if _, disabled := os.LookupEnv("NO_COLOR"); disabled {
		return false
	}

	switch strings.ToLower(strings.TrimSpace(os.Getenv("LOG_COLOR"))) {
	case "always", "true", "1", "yes":
		return true
	case "never", "false", "0", "no":
		return false
	}

	file, ok := w.(*os.File)
	if !ok {
		return false
	}

	stat, err := file.Stat()
	if err != nil {
		return false
	}

	return stat.Mode()&os.ModeCharDevice != 0
}

// With 返回携带额外结构化属性的 logger，便于在业务上下文里串联 request_id / trace_id 等字段。
func With(args ...any) *slog.Logger {
	return slog.Default().With(args...)
}

// GinLogger 把 Gin access log 接入统一 JSON schema，避免默认 [GIN] 文本日志混入 stderr。
func GinLogger() gin.HandlerFunc {
	return func(c *gin.Context) {
		startedAt := time.Now()
		path := c.Request.URL.Path
		rawQuery := c.Request.URL.RawQuery

		c.Next()

		attrs := []any{
			slog.Int("status", c.Writer.Status()),
			slog.String("method", c.Request.Method),
			slog.String("path", path),
			slog.String("client_ip", c.ClientIP()),
			slog.Duration("latency", time.Since(startedAt)),
			slog.Int("bytes", c.Writer.Size()),
		}
		if rawQuery != "" {
			attrs = append(attrs, slog.String("query", rawQuery))
		}
		if requestID := c.GetHeader("X-Request-Id"); requestID != "" {
			attrs = append(attrs, slog.String("request_id", requestID))
		}
		if traceID := c.GetHeader("X-Helmdesk-Trace-Id"); traceID != "" {
			attrs = append(attrs, slog.String("trace_id", traceID))
		}
		if len(c.Errors) > 0 {
			attrs = append(attrs, slog.String("errors", c.Errors.String()))
		}

		logger := slog.Default()
		if c.Writer.Status() >= 500 {
			logger.Error("HTTP request", attrs...)
			return
		}
		logger.Info("HTTP request", attrs...)
	}
}

// GinRecovery 把 Gin panic recovery 接入统一 JSON schema，避免默认 recovery 输出文本堆栈。
func GinRecovery() gin.HandlerFunc {
	return func(c *gin.Context) {
		defer func() {
			if recovered := recover(); recovered != nil {
				slog.Default().Error(
					"HTTP panic",
					slog.Any("panic", recovered),
					slog.String("method", c.Request.Method),
					slog.String("path", c.Request.URL.Path),
					slog.String("client_ip", c.ClientIP()),
					slog.String("stack", string(debug.Stack())),
				)
				c.AbortWithStatus(http.StatusInternalServerError)
			}
		}()

		c.Next()
	}
}

// FromContext 预留：后续打通跨 bridge 关联时，从 context 取出 trace_id 等并附加到 logger。
func FromContext(ctx context.Context) *slog.Logger {
	logger := slog.Default()
	if ctx == nil {
		return logger
	}
	if traceID, ok := ctx.Value(traceIDKey).(string); ok && traceID != "" {
		logger = logger.With(slog.String("trace_id", traceID))
	}
	return logger
}

// ctxKey 是本包私有的 context key 类型，避免与其它包的 key 冲突。
type ctxKey int

const traceIDKey ctxKey = iota

// WithTraceID 把 trace_id 放入 context，供 FromContext 读取并串联日志。
func WithTraceID(ctx context.Context, traceID string) context.Context {
	return context.WithValue(ctx, traceIDKey, traceID)
}
