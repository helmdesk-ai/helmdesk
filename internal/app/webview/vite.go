package webview

import (
	"encoding/json"
	"fmt"
	"html/template"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"helmdesk/internal/app/config"
)

type viteManifestEntry struct {
	File    string   `json:"file"`
	Imports []string `json:"imports"`
	CSS     []string `json:"css"`
}

type AssetSet struct {
	Styles         []string
	ModulePreloads []string
	ScriptURL      string
	DevServerURL   string
	IsDevServer    bool
}

// manifestCache 按文件 mtime 缓存解析后的 Vite manifest，
// 生产环境下 manifest 保持稳定，命中缓存即可跳过读盘 + JSON 反序列化。
type manifestCache struct {
	mu       sync.RWMutex
	path     string
	modTime  time.Time
	manifest map[string]viteManifestEntry
}

// load 按 mtime 命中缓存读取 Vite manifest，未命中或文件变化时重新解析。
func (c *manifestCache) load(path string) (map[string]viteManifestEntry, error) {
	info, err := os.Stat(path)
	if err != nil {
		return nil, fmt.Errorf("stat Vite manifest: %w", err)
	}

	c.mu.RLock()
	if c.manifest != nil && c.path == path && c.modTime.Equal(info.ModTime()) {
		cached := c.manifest
		c.mu.RUnlock()
		return cached, nil
	}
	c.mu.RUnlock()

	c.mu.Lock()
	defer c.mu.Unlock()

	// 双重检查：可能在等锁期间其他 goroutine 已经刷新过缓存。
	info, err = os.Stat(path)
	if err != nil {
		return nil, fmt.Errorf("stat Vite manifest: %w", err)
	}
	if c.manifest != nil && c.path == path && c.modTime.Equal(info.ModTime()) {
		return c.manifest, nil
	}

	content, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read Vite manifest: %w", err)
	}

	fresh := map[string]viteManifestEntry{}
	if err := json.Unmarshal(content, &fresh); err != nil {
		return nil, fmt.Errorf("decode Vite manifest: %w", err)
	}

	c.manifest = fresh
	c.modTime = info.ModTime()
	c.path = path

	return fresh, nil
}

var sharedManifestCache = &manifestCache{}

// 与 resources/views/app.blade.php 语义对齐：根据 localStorage 和系统偏好尽早
// 在渲染前把 .dark class 打到 <html> 上，让主题在首帧就到位，跳过白屏闪烁。
var themePrescriptJS = template.JS(`(function(){var a=localStorage.getItem('appearance')||'system';` +
	`if(a==='dark'||(a==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches)){` +
	`document.documentElement.classList.add('dark');}})();`)

// ThemePrescript 返回可内联到 <script> 中的深色模式预检脚本。
func ThemePrescript() template.JS {
	return themePrescriptJS
}

// ResolveAssets 根据 entry 解析对应 Vite 入口需要的 CSS、modulepreload 与脚本 URL；
// 当 public/hot 存在时切换为 Vite Dev Server 模式。
func ResolveAssets(cfg *config.Config, entry string) (AssetSet, error) {
	hotPath := filepath.Join(cfg.PhpProjectRoot, "public", "hot")
	if hotContent, err := os.ReadFile(hotPath); err == nil {
		devServerURL := strings.TrimSpace(string(hotContent))
		devServerURL = strings.TrimRight(devServerURL, "/")

		return AssetSet{
			DevServerURL: devServerURL,
			ScriptURL:    devServerURL + "/" + strings.TrimLeft(entry, "/"),
			IsDevServer:  true,
		}, nil
	}

	manifestPath := filepath.Join(cfg.PhpProjectRoot, "public", "build", "manifest.json")
	manifest, err := sharedManifestCache.load(manifestPath)
	if err != nil {
		return AssetSet{}, err
	}

	manifestEntry, ok := manifest[entry]
	if !ok {
		return AssetSet{}, fmt.Errorf("vite entry not found in manifest: %s", entry)
	}

	styles := map[string]struct{}{}
	modulePreloads := map[string]struct{}{}
	// visited 既负责去重（同一个 chunk 在多个 entry 路径里都出现时只走一次），
	// 又负责拦截循环导入。Vite 默认产物里 chunk 之间是无环的，
	// 但极端配置或第三方插件可能会构造出环；这里给递归一个明确的终止点。
	visited := map[string]struct{}{}

	var walkImports func(importName string) error
	walkImports = func(importName string) error {
		if _, seen := visited[importName]; seen {
			return nil
		}
		visited[importName] = struct{}{}

		importEntry, ok := manifest[importName]
		if !ok {
			return fmt.Errorf("vite import not found in manifest: %s", importName)
		}

		modulePreloads["/build/"+strings.TrimLeft(importEntry.File, "/")] = struct{}{}
		for _, cssFile := range importEntry.CSS {
			styles["/build/"+strings.TrimLeft(cssFile, "/")] = struct{}{}
		}
		for _, nestedImport := range importEntry.Imports {
			if err := walkImports(nestedImport); err != nil {
				return err
			}
		}

		return nil
	}

	for _, cssFile := range manifestEntry.CSS {
		styles["/build/"+strings.TrimLeft(cssFile, "/")] = struct{}{}
	}
	for _, importName := range manifestEntry.Imports {
		if err := walkImports(importName); err != nil {
			return AssetSet{}, err
		}
	}

	return AssetSet{
		Styles:         sortedKeys(styles),
		ModulePreloads: sortedKeys(modulePreloads),
		ScriptURL:      "/build/" + strings.TrimLeft(manifestEntry.File, "/"),
		IsDevServer:    false,
	}, nil
}

// RenderTags 将 AssetSet 渲染为可直接插入 <head> 的样式与脚本标签。
func RenderTags(assets AssetSet) template.HTML {
	var builder strings.Builder

	if assets.IsDevServer {
		builder.WriteString(fmt.Sprintf("<script type=\"module\" src=\"%s/@vite/client\"></script>\n", assets.DevServerURL))
		builder.WriteString(fmt.Sprintf("<script type=\"module\" src=\"%s\"></script>", assets.ScriptURL))

		return template.HTML(builder.String())
	}

	for _, styleURL := range assets.Styles {
		builder.WriteString(fmt.Sprintf("<link rel=\"stylesheet\" href=\"%s\">\n", styleURL))
	}
	for _, preloadURL := range assets.ModulePreloads {
		builder.WriteString(fmt.Sprintf("<link rel=\"modulepreload\" href=\"%s\">\n", preloadURL))
	}
	builder.WriteString(fmt.Sprintf("<script type=\"module\" src=\"%s\"></script>", assets.ScriptURL))

	return template.HTML(builder.String())
}

// sortedKeys 返回 map 按字典序排序的键列表，用于保证生成的标签顺序稳定。
func sortedKeys(values map[string]struct{}) []string {
	keys := make([]string, 0, len(values))
	for value := range values {
		keys = append(keys, value)
	}
	sort.Strings(keys)

	return keys
}
