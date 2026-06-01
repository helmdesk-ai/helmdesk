package app

import (
	"helmdesk/internal/app/config"
	"log"
	"path/filepath"
	"strings"

	"github.com/gin-gonic/autotls"
	"github.com/gin-gonic/gin"
	"golang.org/x/crypto/acme/autocert"
)

// startHTTPServer 按 HTTPMode 决定走自动 HTTPS 还是普通 HTTP，
// 并在新的 goroutine 中启动 Gin 引擎。
func startHTTPServer(cfg *config.Config, mode HTTPMode, router *gin.Engine) {
	useAutoTLS := false
	switch mode {
	case HTTPModeAutoTLS:
		useAutoTLS = true
	case HTTPModePlain:
		useAutoTLS = false
	case HTTPModeAuto:
		useAutoTLS = len(cfg.ServerNames) > 0
	}

	if useAutoTLS {
		if len(cfg.ServerNames) == 0 {
			log.Fatalln("HTTPModeAutoTLS 要求 cfg.ServerNames 非空")
		}
		log.Printf("启用自动HTTPS模式，域名: %v", cfg.ServerNames)

		certCachePath := filepath.Join(cfg.StoragePath, "certs")
		m := autocert.Manager{
			Prompt:     autocert.AcceptTOS,
			HostPolicy: autocert.HostWhitelist(cfg.ServerNames...),
			Cache:      autocert.DirCache(certCachePath),
		}

		log.Printf("证书缓存路径: %s", certCachePath)
		log.Println("首次启动需要几秒获取证书...")
		log.Printf("访问: https://%s", cfg.ServerNames[0])

		go func() {
			if err := autotls.RunWithManager(router, &m); err != nil {
				log.Fatalln(err.Error())
			}
		}()
		return
	}

	listenAddress := normalizeListenAddress(cfg.HTTPPort)
	log.Printf("HTTP模式，监听地址: %s", listenAddress)
	go func() {
		if err := router.Run(listenAddress); err != nil {
			log.Fatalln(err.Error())
		}
	}()
}

// normalizeListenAddress 把纯端口形式补全成 ":port"，空值时返回 ":80" 作为默认监听地址。
func normalizeListenAddress(address string) string {
	address = strings.TrimSpace(address)
	if address == "" {
		return ":80"
	}

	if strings.Contains(address, ":") {
		return address
	}

	return ":" + address
}
