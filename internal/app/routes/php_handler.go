package routes

import (
	"helmdesk/internal/app/config"
	"log"
	"net/http"
	"os"
	"path/filepath"

	"github.com/dunglas/frankenphp"
)

// 提前定义好静态资源后缀。
var staticExts = map[string]struct{}{
	".css": {}, ".js": {}, ".png": {}, ".jpg": {}, ".jpeg": {},
	".gif": {}, ".svg": {}, ".ico": {}, ".woff": {}, ".woff2": {},
	".ttf": {}, ".pdf": {}, ".txt": {}, ".webp": {},
}

// HandlePHP 返回一个 HandlerFunc：静态资源直接由 Go 输出，其余请求交给 FrankenPHP
// Worker 走 web-worker.php 处理。
func HandlePHP(cfg *config.Config) http.HandlerFunc {
	publicPath := filepath.Join(cfg.PhpProjectRoot, "public")
	phpWorkerPath := "/web-worker.php"
	docRootOption := frankenphp.WithRequestDocumentRoot(publicPath, false)
	splitPathOption, err := frankenphp.WithRequestSplitPath([]string{".php"})
	if err != nil {
		log.Fatalf("无法配置 PHP split path: %v", err)
	}

	return func(w http.ResponseWriter, r *http.Request) {
		requestPath := r.URL.Path

		// 静态文件请求
		ext := filepath.Ext(requestPath)
		if _, isStatic := staticExts[ext]; isStatic {
			fullPath := filepath.Join(publicPath, requestPath)
			if info, err := os.Stat(fullPath); err == nil && !info.IsDir() {
				http.ServeFile(w, r, fullPath)
				return
			}
		}

		// PHP 请求
		phpReq := r.Clone(r.Context())
		phpReq.URL.Path = phpWorkerPath

		req, err := frankenphp.NewRequestWithContext(
			phpReq,
			docRootOption,
			splitPathOption,
			frankenphp.WithRequestEnv(map[string]string{
				"REQUEST_URI": r.RequestURI,
			}),
		)

		if err != nil {
			http.Error(w, err.Error(), http.StatusInternalServerError)
			return
		}

		if err := frankenphp.ServeHTTP(w, req); err != nil {
			log.Printf("PHP error: %v", err)
		}
	}
}
