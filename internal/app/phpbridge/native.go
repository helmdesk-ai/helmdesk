package phpbridge

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"reflect"
	"time"

	"github.com/dunglas/frankenphp"
)

const (
	maxInt   = int(^uint(0) >> 1)
	maxInt64 = uint64(^uint64(0) >> 1)
)

// BridgeError 表示 PHP 侧 Action 抛出的业务/系统异常。
// StatusCode 复用 HTTP 语义：业务层 HttpException 的状态码会被透传过来，
// Go handler 可以直接用 BridgeError.StatusCode 设置响应状态。
type BridgeError struct {
	StatusCode int
	Exception  string
	Message    string
}

// Error 实现 error 接口，输出 PHP 异常类名、消息以及 HTTP 状态码。
func (e *BridgeError) Error() string {
	if e.Exception != "" {
		return fmt.Sprintf("%s: %s (status %d)", e.Exception, e.Message, e.StatusCode)
	}
	return fmt.Sprintf("%s (status %d)", e.Message, e.StatusCode)
}

// IsClientError 语法糖：4xx 一般意味着请求本身有问题（未找到 / 参数非法 / 未授权），
// Go handler 通常要透传原状态码；5xx 可能是 PHP 内部异常，建议一律 500。
func (e *BridgeError) IsClientError() bool {
	return e.StatusCode >= 400 && e.StatusCode < 500
}

// CallNative 通过 FrankenPHP Worker 同步调用一个 Laravel Native Bridge Action 的 handle() 方法
// （底层走 Action::run()，PHP 侧只暴露 App\Actions\Native\ 下的桥接入口）。
//
// 返回值：
//   - 成功时 (result, nil)，result 为 PHP 侧 handle() 的返回值（Data 会被自动 toArray()）。
//   - 业务/系统异常时 (nil, *BridgeError)，调用方可通过 errors.As 取出 StatusCode。
//   - Worker 通信失败时 (nil, error)，普通 error。
func CallNative(workers frankenphp.Workers, class string, params ...any) (any, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	request := map[string]any{
		"class":  class,
		"params": normalizePHPMessageValue(params),
	}

	resp, err := workers.SendMessage(ctx, request, nil)
	if err != nil {
		return nil, fmt.Errorf("worker communication failed: %w", err)
	}

	resMap := unwrapPHPValue(resp).(map[string]any)

	if errObj, ok := resMap["error"].(map[string]any); ok {
		return nil, toBridgeError(errObj)
	}

	return resMap["data"], nil
}

// AsBridgeError 便捷判断：如果 err 是 bridge 业务异常就取出来，否则返回 nil。
func AsBridgeError(err error) *BridgeError {
	var bridgeErr *BridgeError
	if errors.As(err, &bridgeErr) {
		return bridgeErr
	}
	return nil
}

// toBridgeError 把 PHP 侧返回的 error 结构体映射成 *BridgeError，
// 字段缺失时按默认值组装：未给出 status_code 时使用 500。
func toBridgeError(errObj map[string]any) *BridgeError {
	bridgeErr := &BridgeError{StatusCode: http.StatusInternalServerError}

	switch v := errObj["status_code"].(type) {
	case float64:
		bridgeErr.StatusCode = int(v)
	case int64:
		bridgeErr.StatusCode = int(v)
	case int:
		bridgeErr.StatusCode = v
	}
	if msg, ok := errObj["message"].(string); ok {
		bridgeErr.Message = msg
	}
	if name, ok := errObj["exception"].(string); ok {
		bridgeErr.Exception = name
	}

	return bridgeErr
}

// normalizePHPMessageValue 递归把任意 Go 值规范成 FrankenPHP 能稳定传给 PHP 的基本类型集合。
func normalizePHPMessageValue(value any) any {
	switch v := value.(type) {
	case nil, bool, int, int64, float64, string:
		return v

	case []any:
		result := make([]any, len(v))
		for i, item := range v {
			result[i] = normalizePHPMessageValue(item)
		}
		return result

	case map[string]any:
		result := make(map[string]any, len(v))
		for key, item := range v {
			result[key] = normalizePHPMessageValue(item)
		}
		return result
	}

	reflected := reflect.ValueOf(value)
	if !reflected.IsValid() {
		return nil
	}

	switch reflected.Kind() {
	case reflect.Bool:
		return reflected.Bool()

	case reflect.String:
		return reflected.String()

	case reflect.Int:
		return int(reflected.Int())

	case reflect.Int8, reflect.Int16, reflect.Int32:
		return int(reflected.Int())

	case reflect.Int64:
		return reflected.Int()

	case reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uintptr:
		unsigned := reflected.Uint()
		if unsigned <= uint64(maxInt) {
			return int(unsigned)
		}
		if unsigned <= maxInt64 {
			return int64(unsigned)
		}

	case reflect.Uint64:
		unsigned := reflected.Uint()
		if unsigned <= uint64(maxInt) {
			return int(unsigned)
		}
		if unsigned <= maxInt64 {
			return int64(unsigned)
		}

	case reflect.Float32, reflect.Float64:
		return reflected.Float()

	case reflect.Slice, reflect.Array:
		result := make([]any, reflected.Len())
		for i := 0; i < reflected.Len(); i++ {
			result[i] = normalizePHPMessageValue(reflected.Index(i).Interface())
		}
		return result

	case reflect.Map:
		if reflected.Type().Key().Kind() != reflect.String {
			return value
		}

		result := make(map[string]any, reflected.Len())
		iter := reflected.MapRange()
		for iter.Next() {
			result[iter.Key().String()] = normalizePHPMessageValue(iter.Value().Interface())
		}
		return result

	case reflect.Pointer, reflect.Interface:
		if reflected.IsNil() {
			return nil
		}
		return normalizePHPMessageValue(reflected.Elem().Interface())
	}

	return value
}

// unwrapPHPValue 递归地将 FrankenPHP 的 AssociativeArray 转换为标准的 Go 类型。
func unwrapPHPValue(v any) any {
	switch val := v.(type) {
	case frankenphp.AssociativeArray[any]:
		result := make(map[string]any, len(val.Map))
		for k, v := range val.Map {
			result[k] = unwrapPHPValue(v)
		}
		return result

	case []any:
		for i, item := range val {
			val[i] = unwrapPHPValue(item)
		}
		return val

	default:
		return val
	}
}
