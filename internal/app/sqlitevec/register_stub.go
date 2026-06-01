//go:build !linux && !darwin

package sqlitevec

import "fmt"

// register 在非 Linux/macOS 平台直接返回未实现错误的占位实现。
func register(path string) error {
	return fmt.Errorf("sqlite-vec auto extension is not implemented for %s", path)
}
