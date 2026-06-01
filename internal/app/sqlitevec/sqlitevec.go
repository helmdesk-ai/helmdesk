package sqlitevec

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sync"
)

var registerOnce sync.Once

var registerError error

type artifact struct {
	relativePath string
	sha256       string
}

var artifacts = map[string]artifact{
	"linux/amd64": {
		relativePath: filepath.Join("bootstrap", "sqlite_vec", "linux-x64", "vec0.so"),
		sha256:       "5923730861b86c707cca5602b5f91092f9e52a46706dbc6e269fd4bb9c4498e8",
	},
	"linux/arm64": {
		relativePath: filepath.Join("bootstrap", "sqlite_vec", "linux-arm64", "vec0.so"),
		sha256:       "0b84cbd06418ca3040827deddd650539be05be0f657952426b926c8606217437",
	},
	"darwin/arm64": {
		relativePath: filepath.Join("bootstrap", "sqlite_vec", "macos-arm64", "vec0.dylib"),
		sha256:       "193e480c50b59a55977d166f4aaf0e1bc8832d6963516e5950f39e4d2ce0b793",
	},
}

// Register 解析当前平台对应的 sqlite-vec 动态库路径并注册为 SQLite 自动扩展，
// 进程内仅执行一次。
func Register(projectRoot string) error {
	registerOnce.Do(func() {
		path, err := ResolvePath(projectRoot)
		if err != nil {
			registerError = err
			return
		}

		registerError = register(path)
	})

	return registerError
}

// ResolvePath 返回当前 GOOS/GOARCH 对应的 sqlite-vec 动态库绝对路径，
// 并校验文件的 SHA-256 与内置清单一致。
func ResolvePath(projectRoot string) (string, error) {
	key := runtime.GOOS + "/" + runtime.GOARCH
	item, supported := artifacts[key]
	if !supported {
		return "", fmt.Errorf("unsupported platform %s", key)
	}

	path := filepath.Join(projectRoot, item.relativePath)
	if err := verifyArtifact(path, item.sha256); err != nil {
		return "", err
	}

	return path, nil
}

// verifyArtifact 校验指定文件的 SHA-256 与预期值是否一致。
func verifyArtifact(path string, expectedChecksum string) error {
	content, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("read sqlite-vec artifact: %w", err)
	}

	sum := sha256.Sum256(content)
	actualChecksum := hex.EncodeToString(sum[:])
	if actualChecksum != expectedChecksum {
		return fmt.Errorf("sqlite-vec checksum mismatch for %s", path)
	}

	return nil
}
