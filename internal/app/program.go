package app

import (
	"context"
	"helmdesk/internal/app/business/realtime"
	"helmdesk/internal/app/config"
	aiintegration "helmdesk/internal/app/integration/ai"
	knowledgeintegration "helmdesk/internal/app/integration/knowledge"
	mcpintegration "helmdesk/internal/app/integration/mcp"
	"helmdesk/internal/app/logging"
	"helmdesk/internal/app/routes"
	"helmdesk/internal/app/sqlitevec"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"runtime"
	"syscall"
	"time"

	"github.com/dunglas/frankenphp"
	"github.com/gin-gonic/gin"
)

// StartWithCLI 根据命令行配置初始化运行配置并按默认编排启动应用。
func StartWithCLI(cliCfg config.CLIConfig) {
	cfg := config.New(cliCfg)
	spec := defaultSpec(cfg)
	run(cfg, spec)
}

// defaultSpec 集中所有应用编排相关的硬编码。
func defaultSpec(cfg *config.Config) Spec {
	return Spec{
		Workers: []WorkerSpec{
			{
				Name:       "web",
				Kind:       WorkerKindWeb,
				ScriptName: "web-worker.php",
				PoolSize:   runtime.NumCPU() * 2,
			},
			{
				Name:       "schedule",
				Kind:       WorkerKindExtension,
				ScriptName: "artisan-worker.php",
				PoolSize:   1,
				Bind:       func(w frankenphp.Workers) { cfg.ArtisanWorkers = w },
			},
			{
				Name:       "queue",
				Kind:       WorkerKindExtension,
				ScriptName: "queue-worker.php",
				PoolSize:   runtime.NumCPU(),
				Bind:       func(w frankenphp.Workers) { cfg.QueueWorkers = w },
			},
			{
				Name:       "native",
				Kind:       WorkerKindExtension,
				ScriptName: "native-worker.php",
				PoolSize:   runtime.NumCPU() * 2,
				Bind:       func(w frankenphp.Workers) { cfg.NativeWorkers = w },
			},
		},
		OnBoot: []BootStep{
			{Name: "migrate", Command: "migrate --force"},
			{Name: "optimize", Command: "optimize"},
		},
		OnBootRunCondition:   func() bool { return frankenphp.EmbeddedAppPath != "" || !watcherEnabled },
		OnBootCommandTimeout: 5 * time.Second,

		QueuePoll: QueuePollSpec{
			Enabled:            true,
			WorkerName:         "queue",
			InitialTick:        100 * time.Millisecond,
			BusyTick:           10 * time.Millisecond,
			IdleTick:           100 * time.Millisecond,
			LongIdleTick:       1 * time.Second,
			LongIdleAfter:      10,
			JobTimeout:         65 * time.Second,
			ErrorRepeatBackoff: 5 * time.Second,
			ErrorRepeatAt:      10,
		},

		Schedule: nil, // 当前默认无定时任务；如需启用在此追加 ScheduleTask。

		HTTPMode:             HTTPModeAuto,
		InternalBridgeEnable: true,
		ShutdownGraceCron:    5 * time.Second,
	}
}

// run 按 Spec 顺序完成内部桥接、PHP worker、HTTP 服务、Cron 与队列的启动，
// 并阻塞等待退出信号后进行优雅关闭。
func run(cfg *config.Config, spec Spec) {
	// 先绑定 loopback 监听器，拿到端口后写入 PhpEnv，再启动 PHP worker。
	var bridgeListener net.Listener
	if spec.InternalBridgeEnable {
		l, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			log.Fatalf("无法绑定内部桥接监听器: %v", err)
		}
		bridgeListener = l
		cfg.InternalBridgeURL = "http://" + l.Addr().String()
		cfg.PhpEnv[`HELMDESK_INTERNAL_BRIDGE_URL`] = cfg.InternalBridgeURL
		log.Printf("内部桥接监听: %s", cfg.InternalBridgeURL)
	}

	if err := sqlitevec.Register(cfg.PhpProjectRoot); err != nil {
		log.Fatalf("无法加载 sqlite-vec 扩展: %v", err)
	}

	runBootStepsDirect(cfg, spec)

	registerWorkers(cfg, spec.Workers)

	if err := frankenphp.Init(cfg.PhpOption...); err != nil {
		log.Fatalln(err.Error())
	}

	gin.SetMode(gin.ReleaseMode)

	router := gin.New()
	router.Use(logging.GinLogger(), logging.GinRecovery())
	routes.Register(router, cfg)

	if bridgeListener != nil {
		startInternalBridge(cfg, bridgeListener)
	}

	startHTTPServer(cfg, spec.HTTPMode, router)

	cronScheduler := startCron(cfg, spec.Schedule)

	queueCtx, stopQueueWorker := context.WithCancel(context.Background())
	defer stopQueueWorker()

	if spec.QueuePoll.Enabled {
		workers := lookupWorkers(cfg, spec.QueuePoll.WorkerName)
		if workers == nil {
			log.Fatalf("队列 Worker 配置错误：找不到名为 %q 的 worker", spec.QueuePoll.WorkerName)
		}
		go startQueueWorker(queueCtx, workers, spec.QueuePoll)
		log.Println("队列 Worker 已启动")
	}

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, os.Interrupt, syscall.SIGTERM)
	<-quit
	signal.Stop(quit)

	log.Println("正在关闭...")
	stopQueueWorker()

	if cronScheduler != nil {
		cronCtx := cronScheduler.Stop()
		select {
		case <-cronCtx.Done():
		case <-time.After(spec.ShutdownGraceCron):
			log.Println("Cron 停止超时")
		}
	}
}

// startInternalBridge 在已绑定好的 loopback 监听器上启动仅供 PHP 回调的内部 HTTP 服务。
func startInternalBridge(cfg *config.Config, listener net.Listener) {
	bridgeRouter := gin.New()
	bridgeRouter.Use(logging.GinLogger(), logging.GinRecovery())
	routes.RegisterInternalBridge(bridgeRouter, cfg,
		aiintegration.Module(routes.MercureHub(), cfg.NativeWorkers),
		knowledgeintegration.Module(),
		mcpintegration.Module(),
		realtime.Module(routes.MercureHub()),
	)
	bridgeServer := &http.Server{
		Handler:           bridgeRouter,
		ReadHeaderTimeout: 5 * time.Second,
	}
	go func() {
		if err := bridgeServer.Serve(listener); err != nil && err != http.ErrServerClosed {
			log.Fatalf("内部桥接服务器异常退出: %v", err)
		}
	}()
}
