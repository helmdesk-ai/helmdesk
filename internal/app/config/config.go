package config

import (
	"crypto/rand"
	"encoding/base64"
	"log"
	"os"
	"path/filepath"
	"strings"

	"github.com/dunglas/frankenphp"
)

// 嵌入式服务运行配置。
type Config struct {
	PhpProjectRoot      string
	PhpEnv              map[string]string
	WatchPaths          []string
	PhpOption           []frankenphp.Option
	NativeWorkers       frankenphp.Workers
	ArtisanWorkers      frankenphp.Workers
	QueueWorkers        frankenphp.Workers
	StoragePath         string // 自定义 storage 目录路径
	AppKey              []byte // APP_KEY 原始字节，用于附件下载 URL 签名验证
	InternalBridgeToken string
	InternalBridgeURL   string // 供 PHP 回调的 loopback 地址，如 http://127.0.0.1:34567
	// HTTPS 配置
	ServerNames []string // HTTPS域名列表，如：["example.com", "www.example.com"]，留空则使用HTTP模式
	HTTPPort    string   // HTTP端口，默认":80"
	HTTPSPort   string   // HTTPS端口，默认":443"
}

// 命令行覆盖项。
type CLIConfig struct {
	Port        string
	Domain      string
	StoragePath string
}

// 构造运行配置。
func New(cli CLIConfig) *Config {
	cfg := &Config{
		PhpProjectRoot: ".",
		PhpEnv:         make(map[string]string),
		ServerNames:    []string{},
		HTTPPort:       "80",
		HTTPSPort:      "443",
	}

	cfg.PhpEnv[`MAX_REQUESTS`] = "500"

	internalBridgeToken := strings.TrimSpace(os.Getenv("HELMDESK_INTERNAL_BRIDGE_TOKEN"))
	if internalBridgeToken == "" {
		generatedToken, err := generateAppKey()
		if err != nil {
			log.Fatalf("无法生成内部桥接令牌: %v", err)
		}
		internalBridgeToken = generatedToken
	}
	cfg.InternalBridgeToken = internalBridgeToken
	cfg.PhpEnv[`HELMDESK_INTERNAL_BRIDGE_TOKEN`] = internalBridgeToken

	// Loopback 桥接地址由 program.go 实际监听后填充；这里先留空，PhpEnv 交给运行时覆盖。

	// 发布版把可变数据放到二进制同级 storage。
	var storagePath string
	if cli.StoragePath != "" {
		absoluteStoragePath, err := filepath.Abs(cli.StoragePath)
		if err != nil {
			log.Fatalf("无法解析数据目录 %s: %v", cli.StoragePath, err)
		}
		storagePath = absoluteStoragePath
	} else {
		rootPath := ResolveRootPath()
		storagePath = filepath.Join(rootPath, "storage")
	}
	absoluteStoragePath, err := filepath.Abs(storagePath)
	if err != nil {
		log.Fatalf("无法解析数据目录 %s: %v", storagePath, err)
	}
	storagePath = absoluteStoragePath
	cfg.StoragePath = storagePath
	log.Printf("数据目录: %s", storagePath)

	// Laravel worker 通过环境变量读取外置 storage 路径。
	cfg.PhpEnv[`LARAVEL_STORAGE_PATH`] = storagePath

	if cli.Port != "" {
		cfg.HTTPPort = cli.Port
	}
	if cli.Domain != "" {
		cfg.ServerNames = strings.Split(cli.Domain, ",")
		for i, name := range cfg.ServerNames {
			cfg.ServerNames[i] = strings.TrimSpace(name)
		}
	}

	if frankenphp.EmbeddedAppPath != "" {
		cfg.PhpProjectRoot = frankenphp.EmbeddedAppPath
	}
	ensureStorageStructure(storagePath, cfg.PhpProjectRoot)

	// APP_KEY 已在 ensureStorageStructure 中确保存在，直接从 .env 提取原始字节用于附件下载签名验证。
	cfg.AppKey = readAppKeyFromEnv(envFilePath(cfg.PhpProjectRoot, storagePath))
	cfg.PhpOption = append(cfg.PhpOption, frankenphp.WithPhpIni(phpIniOverrides(storagePath)))

	// 只监听会影响 PHP 运行结果的目录。
	cfg.WatchPaths = []string{
		filepath.Join(cfg.PhpProjectRoot, "routes"),
		filepath.Join(cfg.PhpProjectRoot, "app"),
		filepath.Join(cfg.PhpProjectRoot, "bootstrap"),
		filepath.Join(cfg.PhpProjectRoot, "config"),
		filepath.Join(cfg.PhpProjectRoot, "lang"),
	}

	return cfg
}

// phpIniOverrides 返回针对当前 storage 路径定制的 php.ini 覆盖项。
func phpIniOverrides(storagePath string) map[string]string {
	return map[string]string{
		`post_max_size`:       `200M`,
		`upload_max_filesize`: `200M`,
		`memory_limit`:        `256M`,
		`max_execution_time`:  `0`,
		`max_input_time`:      `-1`,
		`opcache.file_cache`:  filepath.Join(storagePath, "framework", "cache", "opcache"),
	}
}

// 返回应用根目录。
func ResolveRootPath() string {
	exePath, err := os.Executable()
	if err != nil {
		log.Fatal(err)
	}
	exePath, err = filepath.EvalSymlinks(exePath)
	if err != nil {
		log.Fatalf("无法解析可执行文件路径 %s: %v", exePath, err)
	}
	lowerExePath := strings.ToLower(exePath)
	if strings.Contains(lowerExePath, "go-build") || strings.Contains(lowerExePath, strings.ToLower(os.TempDir())) {
		wd, err := os.Getwd()
		if err != nil {
			log.Fatal(err)
		}
		return wd
	}
	return filepath.Dir(exePath)
}

// ensureStorageStructure 创建 Laravel 运行所需的 storage 目录、SQLite 文件、
// public/storage 链接，并在首次启动时生成 .env 与 APP_KEY。
func ensureStorageStructure(storagePath string, phpProjectRoot string) {
	// Laravel 必需的 storage 目录结构。
	ensureDir(filepath.Join(storagePath, "app", "public"))
	ensureDir(filepath.Join(storagePath, "framework", "cache", "data"))
	ensureDir(filepath.Join(storagePath, "framework", "cache", "opcache"))
	ensureDir(filepath.Join(storagePath, "framework", "sessions"))
	ensureDir(filepath.Join(storagePath, "framework", "views")) // Blade 编译必需。
	ensureDir(filepath.Join(storagePath, "logs"))

	// SQLite 文件提前创建，让首次请求时直接进入业务路径。
	ensureDir(filepath.Join(storagePath, "database"))
	ensureFile(filepath.Join(storagePath, "database", "main.sqlite"))
	ensureFile(filepath.Join(storagePath, "database", "rag.sqlite"))
	ensureFile(filepath.Join(storagePath, "database", "session.sqlite"))
	ensureFile(filepath.Join(storagePath, "database", "cache.sqlite"))
	ensureFile(filepath.Join(storagePath, "database", "jobs.sqlite"))

	// autocert 会把证书缓存到这里，HTTP 模式下保留空目录也无害。
	ensureDir(filepath.Join(storagePath, "certs"))
	ensurePublicStorageLink(storagePath, phpProjectRoot)

	// 嵌入式运行时把 .env 放在外置 storage。
	if frankenphp.EmbeddedAppPath != "" {
		envPath := filepath.Join(storagePath, ".env")
		ensureEnvFile(envPath, storagePath)
		envData, err := os.ReadFile(envPath)
		if err != nil {
			log.Fatalf("无法读取配置文件.env: %v", err.Error())
		}
		err = os.WriteFile(filepath.Join(frankenphp.EmbeddedAppPath, ".env"), envData, 0644)
		if err != nil {
			log.Fatalf("无法写入.env文件 %s", err.Error())
		}
		clearEmbeddedBootstrapCache()
		return
	}

	ensureEnvFile(filepath.Join(phpProjectRoot, ".env"), storagePath)
}

// ensureEnvFile 在 envPath 不存在时生成一份带 APP_KEY 与 LARAVEL_STORAGE_PATH 的 .env，
// 任一步出错都直接 log.Fatal，让启动阶段的失败立即暴露。
func ensureEnvFile(envPath, storagePath string) {
	if _, err := os.Stat(envPath); !os.IsNotExist(err) {
		return
	}
	appKey, err := generateAppKey()
	if err != nil {
		log.Fatalf("无法生成APP_KEY: %v", err.Error())
	}
	envData := []byte("APP_KEY=" + appKey + "\nLARAVEL_STORAGE_PATH=" + storagePath + "\n")
	if err := os.WriteFile(envPath, envData, 0644); err != nil {
		log.Fatalf("无法写入.env文件 %s", err.Error())
	}
	log.Printf("首次运行：已创建配置文件 %s", envPath)
}

// clearEmbeddedBootstrapCache 清理嵌入式应用 bootstrap/cache 下的预编译缓存，
// 让发布版升级后 Laravel 重新生成与新代码匹配的配置/路由缓存。
func clearEmbeddedBootstrapCache() {
	cacheDir := filepath.Join(frankenphp.EmbeddedAppPath, "bootstrap", "cache")
	entries := []string{
		"config.php",
		"events.php",
		"routes-v7.php",
		"routes.php",
	}

	for _, entry := range entries {
		path := filepath.Join(cacheDir, entry)
		if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
			log.Fatalf("无法清理 Laravel 缓存文件 %s: %v", path, err)
		}
	}
}

// ensurePublicStorageLink 保证 public/storage 指向外置 storage/app/public，
// 已是正确链接则不动，否则重建。
func ensurePublicStorageLink(storagePath string, phpProjectRoot string) {
	linkPath := filepath.Join(phpProjectRoot, "public", "storage")
	targetPath := filepath.Join(storagePath, "app", "public")

	if err := os.MkdirAll(filepath.Dir(linkPath), 0755); err != nil {
		log.Fatalf("无法创建 public 目录 %s: %v", filepath.Dir(linkPath), err)
	}

	if isCorrectLink(linkPath, targetPath) {
		return
	}
	removeStaleLink(linkPath)
	createLink(linkPath, targetPath)
}

// isCorrectLink 判断 linkPath 是否为指向 targetPath 的符号链接。
func isCorrectLink(linkPath string, targetPath string) bool {
	info, err := os.Lstat(linkPath)
	if err != nil {
		return false
	}
	if info.Mode()&os.ModeSymlink == 0 {
		return false
	}
	currentTarget, err := os.Readlink(linkPath)
	if err != nil {
		return false
	}
	if !filepath.IsAbs(currentTarget) {
		currentTarget = filepath.Join(filepath.Dir(linkPath), currentTarget)
	}
	currentAbs, _ := filepath.Abs(currentTarget)
	targetAbs, _ := filepath.Abs(targetPath)
	return strings.EqualFold(currentAbs, targetAbs)
}

// removeStaleLink 移除指向错误目标的 public/storage 链接；
// 在嵌入式模式下若是普通目录可强制重建，其它情况遇到非链接则中止启动。
func removeStaleLink(linkPath string) {
	info, err := os.Lstat(linkPath)
	if os.IsNotExist(err) {
		return
	}
	if err != nil {
		log.Fatalf("无法检查 public/storage 链接 %s: %v", linkPath, err)
	}

	if info.Mode()&os.ModeSymlink != 0 {
		if err := os.Remove(linkPath); err != nil {
			log.Fatalf("无法更新 public/storage 链接 %s: %v", linkPath, err)
		}
		return
	}

	if frankenphp.EmbeddedAppPath != "" {
		// 嵌入式应用目录可重建 public/storage。
		if err := os.RemoveAll(linkPath); err != nil {
			log.Fatalf("无法更新 embedded public/storage 目录 %s: %v", linkPath, err)
		}
		return
	}

	log.Fatalf("public/storage 已存在且不是符号链接: %s", linkPath)
}

// createLink 创建 public/storage -> target 的符号链接。
func createLink(linkPath string, targetPath string) {
	if err := os.Symlink(targetPath, linkPath); err != nil {
		log.Fatalf("无法创建 public/storage 符号链接: %v", err)
	}
}

// ensureDir 递归创建目录，失败直接终止程序。
func ensureDir(path string) {
	if err := os.MkdirAll(path, 0755); err != nil {
		log.Fatalf("无法创建目录 %s: %v", path, err)
	}
}

// ensureFile 若文件不存在则创建一个空文件，失败直接终止程序。
func ensureFile(path string) {
	if _, err := os.Stat(path); os.IsNotExist(err) {
		log.Printf("创建文件: %s", path)
		file, err := os.Create(path)
		if err != nil {
			log.Fatalf("无法创建文件 %s: %v", path, err)
		}
		err = file.Close()
		if err != nil {
			log.Fatalf("无法关闭文件 %s: %v", path, err)
		}
	}
}

// generateAppKey 生成 Laravel 风格的 base64 APP_KEY 字符串。
func generateAppKey() (string, error) {
	const keyLength = 32
	key := make([]byte, keyLength)
	if _, err := rand.Read(key); err != nil {
		return "", err
	}
	return "base64:" + base64.StdEncoding.EncodeToString(key), nil
}

// 返回当前运行模式下 .env 文件的真实路径：嵌入式发布版放在外置 storage，其它情况随项目根目录。
func envFilePath(phpProjectRoot, storagePath string) string {
	if frankenphp.EmbeddedAppPath != "" {
		return filepath.Join(storagePath, ".env")
	}
	return filepath.Join(phpProjectRoot, ".env")
}

// 从已知存在的 .env 文件中提取 APP_KEY 原始字节。
func readAppKeyFromEnv(envPath string) []byte {
	data, err := os.ReadFile(envPath)
	if err != nil {
		log.Fatalf("无法读取 .env %s: %v", envPath, err)
	}
	for _, line := range strings.Split(string(data), "\n") {
		if v, ok := strings.CutPrefix(strings.TrimSpace(line), "APP_KEY=base64:"); ok {
			raw, err := base64.StdEncoding.DecodeString(strings.TrimSpace(v))
			if err != nil {
				log.Fatalf("APP_KEY base64 解码失败: %v", err)
			}
			return raw
		}
	}
	log.Fatalf("在 %s 中未找到 APP_KEY", envPath)
	return nil
}
