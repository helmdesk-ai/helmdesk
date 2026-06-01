package app

import (
	"helmdesk/internal/app/config"
	"log"
	"os"
	"path/filepath"
	"time"

	"github.com/dunglas/frankenphp"
	"github.com/robfig/cron/v3"
)

// runBootStepsDirect 在 worker 初始化前执行启动阶段必须完成的 Artisan 命令。
func runBootStepsDirect(cfg *config.Config, spec Spec) {
	if spec.OnBootRunCondition != nil && !spec.OnBootRunCondition() {
		return
	}
	for _, step := range spec.OnBoot {
		log.Printf("开始执行 %s...", step.Name)
		runLaravelCommandDirect(cfg, step.Name, step.Args)
	}
}

// runLaravelCommandDirect 通过 CLI 模式执行 Artisan 命令，用于数据库迁移等 worker 前置步骤。
func runLaravelCommandDirect(cfg *config.Config, name string, args []string) {
	for key, value := range cfg.PhpEnv {
		if err := os.Setenv(key, value); err != nil {
			log.Fatalf("无法设置环境变量 %s: %v", key, err)
		}
	}

	artisanScript := filepath.Join(cfg.PhpProjectRoot, "artisan")
	exitCode := frankenphp.ExecuteScriptCLI(artisanScript, append([]string{"artisan"}, args...))
	if exitCode != 0 {
		log.Fatalf("启动命令失败: %s, exit code: %d", name, exitCode)
	}
}

// startCron 注册并启动定时任务调度器，任务列表为空时返回 nil 跳过 Cron 启动。
func startCron(cfg *config.Config, tasks []ScheduleTask) *cron.Cron {
	if len(tasks) == 0 {
		return nil
	}
	c := cron.New()
	for _, task := range tasks {
		command := task.Command
		_, err := c.AddFunc(task.CronExpression, func() {
			go runLaravelCommand(cfg.ArtisanWorkers, command, 5*time.Second)
		})
		if err != nil {
			log.Printf("无法添加 Cron 任务 [%s]: %v", task.Command, err)
		} else {
			log.Printf("任务已注册: [%s] -> %s", task.CronExpression, task.Command)
		}
	}
	c.Start()
	log.Println("Go Cron 调度器已启动")
	return c
}
