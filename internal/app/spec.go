package app

import (
	"time"

	"github.com/dunglas/frankenphp"
)

// WorkerKind 区分 FrankenPHP 两类 worker：
//   - WorkerKindWeb 走 frankenphp.WithWorkers，挂在 HTTP 入口下，由 /web-worker.php 处理普通 Web 请求。
//   - WorkerKindExtension 走 frankenphp.WithExtensionWorkers，独立的进程池，Go 侧通过 SendMessage 同步调用。
type WorkerKind int

const (
	WorkerKindWeb WorkerKind = iota
	WorkerKindExtension
)

// WorkerSpec 描述一个 FrankenPHP worker 类型的启动参数。
// Bind 用于把启动出来的 Workers 句柄回填到外部（通常是 *config.Config 的字段），
// 业务模块以 cfg.NativeWorkers 这种字段方式拿到句柄，让 Spec 保持只描述编排意图。
type WorkerSpec struct {
	Name       string
	Kind       WorkerKind
	ScriptName string // 相对 public/ 的脚本名，例如 "web-worker.php"
	PoolSize   int
	Bind       func(frankenphp.Workers)
}

// BootStep 启动期一次性执行的 Artisan 命令。
type BootStep struct {
	Name string
	Args []string
}

// QueuePollSpec 控制 Go 侧拉模式队列消费的节奏。
// Enabled = false 时该节点完全跳过队列消费（多实例部署时 web 节点可关掉）。
type QueuePollSpec struct {
	Enabled            bool
	WorkerName         string        // 关联的 WorkerSpec.Name，默认为 "queue"
	InitialTick        time.Duration // 启动后的初始 tick 间隔
	BusyTick           time.Duration // 拉到任务后下一轮的 tick 间隔
	IdleTick           time.Duration // 空闲一段时间内的 tick 间隔
	LongIdleTick       time.Duration // 长时间空闲后的 tick 间隔
	LongIdleAfter      int           // 连续空轮次后切到 LongIdleTick
	JobTimeout         time.Duration // 单次 pop+process 的超时
	ErrorRepeatBackoff time.Duration // 同一错误反复出现时的 tick 间隔
	ErrorRepeatAt      int           // 同一错误连续多少次后切到 ErrorRepeatBackoff
}

// ScheduleTask Go 侧 cron 调用 Artisan 的任务。
type ScheduleTask struct {
	CronExpression string
	Command        string
}

// HTTPMode 控制 HTTP/HTTPS 服务器形态。
type HTTPMode int

const (
	// HTTPModeAuto 默认行为：cfg.ServerNames 非空走 autotls，否则纯 HTTP。
	HTTPModeAuto HTTPMode = iota
	// HTTPModePlain 强制纯 HTTP（多实例部署前置 LB 终结 TLS 时使用）。
	HTTPModePlain
	// HTTPModeAutoTLS 强制 autotls（要求 cfg.ServerNames 非空）。
	HTTPModeAutoTLS
)

// Spec 描述一个节点的应用编排：哪些 worker、哪些启动步骤、是否消费队列、是否跑 cron、HTTP 模式。
// 多实例部署时不同角色的节点用不同的 Spec 即可，包代码保持稳定。
//
// 调用方通过 defaultSpec(cfg) 获取完整编排；零值结构不作为有效配置。
type Spec struct {
	Workers              []WorkerSpec
	OnBoot               []BootStep
	OnBootRunCondition   func() bool // 满足才执行 OnBoot；nil 视为始终执行
	OnBootCommandTimeout time.Duration

	QueuePoll QueuePollSpec
	Schedule  []ScheduleTask

	HTTPMode             HTTPMode
	InternalBridgeEnable bool

	ShutdownGraceCron time.Duration // cron 停止的最大等待时间
}
