package main

import (
	"flag"
	"fmt"
	"helmdesk/internal/app"
	"helmdesk/internal/app/config"
	"helmdesk/internal/app/logging"
	"os"
)

// main 是程序入口，分发 artisan 子命令或解析 CLI 参数后启动嵌入式服务。
func main() {
	// 先建立统一的结构化日志地基，让后续（含标准库 log）的输出都是 stderr 上的 JSON 行。
	logging.Setup()

	if len(os.Args) > 1 && os.Args[1] == "artisan" {
		if len(os.Args) < 3 {
			fmt.Println("用法: helmdesk artisan <command> [args...]")
			os.Exit(1)
		}
		app.RunArtisan(os.Args[2:])
		return
	}

	// 主命令参数
	port := flag.String("port", "", "HTTP端口 (如: 80 或 :80)")
	domain := flag.String("domain", "", "域名，多个用逗号分隔，设置后自动启用HTTPS")
	storagePath := flag.String("storage-path", "", "数据存储路径")
	flag.Parse()

	// 构建配置
	cfg := config.CLIConfig{
		Port:        *port,
		Domain:      *domain,
		StoragePath: *storagePath,
	}

	app.StartWithCLI(cfg)
}
