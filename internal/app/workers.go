package app

import (
	"context"
	"fmt"
	"helmdesk/internal/app/config"
	"log"
	"path/filepath"
	"runtime"
	"slices"
	"time"

	"github.com/dunglas/frankenphp"
)

// registerWorkers 把 WorkerSpec 列表翻译成 frankenphp 的 Option，
// 并按类型分别注册 Web Worker 或 Extension Worker。
func registerWorkers(cfg *config.Config, workers []WorkerSpec) {
	baseOptions := []frankenphp.WorkerOption{
		frankenphp.WithWorkerEnv(cfg.PhpEnv),
		frankenphp.WithWorkerMaxFailures(0),
	}
	if frankenphp.EmbeddedAppPath == "" && watcherEnabled {
		baseOptions = append(baseOptions, frankenphp.WithWorkerWatchMode(cfg.WatchPaths))
	}

	for _, w := range workers {
		scriptPath := filepath.Join(cfg.PhpProjectRoot, "public", w.ScriptName)
		poolSize := w.PoolSize
		if poolSize <= 0 {
			poolSize = runtime.NumCPU() * 2
		}

		// 给每个 worker 一份独立的 options 切片：frankenphp.WithExtensionWorkers
		// 内部会 append 一个 withExtensionWorkers(w) marker；多个 worker 共享
		// 同一份 baseOptions 的底层数组（且有 spare cap）时，后续 append 会写入同一
		// 个槽位互相覆盖，让前面 worker 的 internalWorker 始终拿不到回填。
		// slices.Clone 给每个 worker 独立的底层数组，从根本上隔离 append。
		perWorkerOptions := slices.Clone(baseOptions)

		switch w.Kind {
		case WorkerKindWeb:
			cfg.PhpOption = append(cfg.PhpOption, frankenphp.WithWorkers(w.Name, scriptPath, poolSize, perWorkerOptions...))
		case WorkerKindExtension:
			handles, option := frankenphp.WithExtensionWorkers(w.Name, scriptPath, poolSize, perWorkerOptions...)
			cfg.PhpOption = append(cfg.PhpOption, option)
			if w.Bind != nil {
				w.Bind(handles)
			}
		}
	}
}

// lookupWorkers 按名称返回 cfg 中已绑定的 Extension Worker 句柄。
func lookupWorkers(cfg *config.Config, name string) frankenphp.Workers {
	switch name {
	case "queue":
		return cfg.QueueWorkers
	case "schedule":
		return cfg.ArtisanWorkers
	case "native":
		return cfg.NativeWorkers
	}
	return nil
}

// runLaravelCommand 在指定 worker 池中运行 Laravel Artisan 命令。
func runLaravelCommand(workers frankenphp.Workers, command string, timeout time.Duration) {
	if timeout <= 0 {
		timeout = 5 * time.Second
	}
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()

	resp, err := workers.SendMessage(ctx, map[string]any{"command": command}, nil)
	if err != nil {
		log.Printf("[向Laravel发送消息失败] 命令: %s, 失败原因: %v", command, err)
		return
	}

	arr := resp.(frankenphp.AssociativeArray[any])
	if output, found := arr.Map["output"]; found {
		log.Printf("%v", output)
		return
	}
	log.Printf("[运行Laravel命令失败] 命令: %s, 结果不完整: %v", command, arr.Map)
}

// startQueueWorker 启动队列 Worker 拉取循环。
func startQueueWorker(ctx context.Context, workers frankenphp.Workers, poll QueuePollSpec) {
	tick := poll.InitialTick
	if tick <= 0 {
		tick = 100 * time.Millisecond
	}
	ticker := time.NewTicker(tick)
	defer ticker.Stop()

	consecutiveEmpty := 0
	lastError := ""
	errorCount := 0

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}

		requestCtx, cancel := context.WithTimeout(context.Background(), poll.JobTimeout)
		resp, err := workers.SendMessage(requestCtx, map[string]any{}, nil)
		cancel()

		if err != nil {
			log.Printf("[队列 Worker 错误] %v", err)
			continue
		}

		arr := resp.(frankenphp.AssociativeArray[any])

		if processed, found := arr.Map["processed"]; found {
			if isProcessed, ok := processed.(bool); ok && isProcessed {
				consecutiveEmpty = 0
				errorCount = 0
				lastError = ""
				if job, found := arr.Map["job"]; found {
					log.Printf("[队列任务已处理] %v", job)
				}
				ticker.Reset(poll.BusyTick)
			} else {
				consecutiveEmpty++
				if consecutiveEmpty >= poll.LongIdleAfter {
					ticker.Reset(poll.LongIdleTick)
				} else {
					ticker.Reset(poll.IdleTick)
				}
			}
		}

		if errMsg, found := arr.Map["error"]; found && errMsg != nil {
			currentError := fmt.Sprintf("%v", errMsg)
			if currentError != lastError {
				log.Printf("[队列任务处理错误] %v", errMsg)
				lastError = currentError
				errorCount = 1
			} else {
				errorCount++
				if errorCount >= poll.ErrorRepeatAt {
					ticker.Reset(poll.ErrorRepeatBackoff)
				}
			}
		}
	}
}
