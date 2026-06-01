package routes

import (
	"context"
	"helmdesk/internal/app/config"
	"helmdesk/internal/app/logging"
	"log"
	"os"
	"path/filepath"

	"github.com/dunglas/mercure"
)

// 全局 Mercure Hub。
var globalHub *mercure.Hub

// MercureHub 返回当前进程内的 Mercure Hub。
// 仅在 InitMercureHub 完成后可用，更早调用会返回 nil，调用方需自行做空检查。
func MercureHub() *mercure.Hub {
	return globalHub
}

// InitMercureHub 初始化全局 Mercure Hub。
func InitMercureHub(cfg *config.Config) error {
	storagePath := cfg.StoragePath
	if storagePath == "" {
		storagePath = filepath.Join(cfg.PhpProjectRoot, "storage")
	}
	dbPath := filepath.Join(storagePath, "database", "mercure.db")
	if err := os.MkdirAll(filepath.Dir(dbPath), 0755); err != nil {
		return err
	}
	logger := logging.With("component", "mercure")
	subscriberList := mercure.NewSubscriberList(1000) // 最大订阅者数量
	transport, err := mercure.NewBoltTransport(subscriberList, logger, dbPath, "", uint64(60), 1e7)
	if err != nil {
		return err
	}

	globalHub, err = mercure.NewHub(
		context.Background(),
		mercure.WithAnonymous(),
		mercure.WithSubscriptions(),
		mercure.WithTransport(transport),
	)
	if err != nil {
		return err
	}

	log.Printf("Mercure Hub 已初始化，数据库: %s", dbPath)
	return nil
}
