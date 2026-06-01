package routes

import (
	"helmdesk/internal/app/business/attachment"
	"helmdesk/internal/app/business/demo"
	"helmdesk/internal/app/business/native"
	"helmdesk/internal/app/business/publicweb"
	"helmdesk/internal/app/business/reception"
	"helmdesk/internal/app/business/telegram"
	"helmdesk/internal/app/config"
	"log"

	"github.com/gin-gonic/gin"
)

// 桥接模块代表一个挂载到内部桥接路由组下的业务模块。
// 新业务只需实现一个 func(*gin.RouterGroup) 并在启动时传给 RegisterInternalBridge，
// 本包保持稳定。
type BridgeModule func(group *gin.RouterGroup)

// 把仅供 PHP 回调的内部路由挂到独立的 loopback 监听器上。
// 业务模块以 BridgeModule 形式传入，由调用方（通常是 program.go）负责组合。
func RegisterInternalBridge(router *gin.Engine, cfg *config.Config, modules ...BridgeModule) {
	bridge := router.Group("/_helmdesk/internal", requireBridgeToken(cfg.InternalBridgeToken))
	for _, module := range modules {
		module(bridge)
	}
}

// Register 在主 Gin 引擎上挂载 Mercure、Go 业务路由以及兜底的 PHP 处理器。
func Register(router *gin.Engine, cfg *config.Config) {
	// 初始化 Mercure Hub
	if err := InitMercureHub(cfg); err != nil {
		log.Fatalf("初始化 Mercure Hub 失败: %v", err)
	}
	// Mercure 路由
	router.Any("/.well-known/mercure", func(c *gin.Context) {
		globalHub.ServeHTTP(c.Writer, c.Request)
	})

	// Go 路由
	router.GET("/ping", func(c *gin.Context) {
		demo.Ping(c.Writer, c.Request)
	})

	// Native 路由（调用 PHP 方法）
	router.GET("/native/example", func(c *gin.Context) {
		native.ExampleHandler(cfg)(c.Writer, c.Request)
	})

	// 访客独立页由 Go 直接渲染，数据通过 Native bridge 向 Laravel 获取。
	router.GET("/ch/:code", publicweb.StandaloneHandler(cfg))

	// 网站渠道小部件公开入口：安装脚本负责注入按钮，iframe 页面复用访客接待画布。
	router.GET("/embed/widget.js", publicweb.WidgetScriptHandler())
	router.GET("/embed/widget/:code/bootstrap", publicweb.WidgetBootstrapHandler(cfg))
	router.GET("/embed/widget/:code", publicweb.WidgetFrameHandler(cfg))

	// 接待 actor 注册中心：网站与 Telegram 等渠道共享同一池，按 conversation 收敛 actor。
	receptionRegistry := reception.NewRegistry(cfg.NativeWorkers, globalHub)

	// 访客接待接口：C 端从独立页 / widget 发起的所有读写都走 Go，由 Go 回调 PHP Action 落库。
	reception.RegisterRoutes(router, cfg, receptionRegistry)

	// Telegram Bot 入站 webhook：校验 secret 头后回调 PHP 落库，并复用同一接待 actor 池。
	telegram.RegisterRoutes(router, cfg, receptionRegistry)

	// 附件下载（Go 直出，绕过 PHP）
	attachment.RegisterRoutes(router, cfg)

	// PHP 路由（处理所有其他请求）
	phpHandler := HandlePHP(cfg)
	router.NoRoute(func(c *gin.Context) {
		phpHandler(c.Writer, c.Request)
	})
}
