package app

import (
	"helmdesk/internal/app/config"
	"log"
	"time"

	"github.com/robfig/cron/v3"
)

// runBootSteps 在满足条件时依次执行启动阶段需要跑的 Artisan 命令。
func runBootSteps(cfg *config.Config, spec Spec) {
	if spec.OnBootRunCondition != nil && !spec.OnBootRunCondition() {
		return
	}
	for _, step := range spec.OnBoot {
		log.Printf("开始执行 %s...", step.Name)
		runLaravelCommand(cfg.ArtisanWorkers, step.Command, spec.OnBootCommandTimeout)
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
