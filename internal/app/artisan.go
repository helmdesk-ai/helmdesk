package app

import (
	"helmdesk/internal/app/config"
	"log"
	"os"
	"path/filepath"

	"github.com/dunglas/frankenphp"
)

// RunArtisan 以 CLI 模式直接运行 Laravel Artisan 命令。
func RunArtisan(cmdArgs []string) {
	cfg := config.New(config.CLIConfig{})

	// 设置环境变量
	for key, value := range cfg.PhpEnv {
		err := os.Setenv(key, value)
		if err != nil {
			log.Printf("无法设置环境变量: %s=%s", key, value)
			return
		}
	}

	// 使用 ExecuteScriptCLI 直接执行，支持完整的交互式 TTY
	artisanScript := filepath.Join(cfg.PhpProjectRoot, "artisan")
	args := append([]string{"artisan"}, cmdArgs...)
	exitCode := frankenphp.ExecuteScriptCLI(artisanScript, args)
	os.Exit(exitCode)
}
