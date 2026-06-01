package demo

import (
	"fmt"
	"net/http"
)

// Ping 是嵌入式 Go 路由用于本地冒烟测试的轻量健康检查端点。
func Ping(w http.ResponseWriter, r *http.Request) {
	fmt.Fprintln(w, "ping")
}
