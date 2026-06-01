package tools

import (
	"context"
	"crypto/sha1"
	"encoding/hex"
	"io"
	"log"
	"regexp"
	"strings"

	einomcp "github.com/cloudwego/eino-ext/components/tool/mcp"
	"github.com/cloudwego/eino/components/tool"
	"github.com/cloudwego/eino/schema"

	mcpclient "helmdesk/internal/app/integration/mcp"
)

// 一次对话允许暴露给 LLM 的 MCP 工具数量硬上限。
// OpenAI / Anthropic 实测都接受相当数量的 function declarations，按 64 一刀切：
// 让 prompt 体积、误调用概率、token 配额三项指标保持在可预测范围。后续按需放宽。
const maxMcpToolsPerChat = 64

// 给 LLM 看的工具名最大长度（OpenAI function name 上限就是 64）。
const maxLLMToolNameLength = 64

// LLM 函数名允许的字符集合。其他字符（含中文、空格、点号）由 sanitize 阶段统一替换成 `_`。
var llmToolNameSafeChars = regexp.MustCompile(`[^a-zA-Z0-9_-]`)

// McpServerSpec 描述一台 MCP 服务在本轮对话里要挂哪些工具。
//
// 与 ai.McpServerForChat 形成边界：tools 包独立于 chat_stream 的请求结构，方便单元测试覆盖。
type McpServerSpec struct {
	ID             string
	Slug           string
	Name           string
	Transport      string
	EndpointURL    string
	Credentials    map[string]string
	Headers        map[string]string
	TimeoutSeconds int
	// ToolNames 列表非空时只暴露白名单中的工具；列表为空时整台 server 跳过远端调用。
	ToolNames []string
}

// McpToolsHandle 是 BuildMcpTools 的返回值。
//
// Tools 是已经按 LLM 友好规则重命名过的工具列表，可以直接挂到 adk.ChatModelAgent；
// Display 把"暴露给 LLM 的名字"映射到"<服务名> / <原始工具名>"这种人类可读标签，
// 流式事件里 chat_stream 会用它把 tool_call / tool_result 的提示渲染得更友好。
//
// closers 是本轮对话占用的全部 mcp client，调用方必须在对话结束时 defer Close()。
type McpToolsHandle struct {
	Tools   []tool.BaseTool
	Display map[string]string
	closers []io.Closer
}

// Close 关闭本次对话占用的所有 MCP 客户端。重复调用安全。
func (h *McpToolsHandle) Close() {
	for _, c := range h.closers {
		_ = c.Close()
	}
	h.closers = nil
}

// BuildMcpTools 为本轮对话构造所有 MCP 工具。
//
// 每台 server 都会单独建一个 mcp-go streamable_http 客户端 + initialize，再交给
// eino-ext/components/tool/mcp.GetTools 拿到原始 BaseTool；之后再套一层 renamedTool
// 把名字改成 LLM 友好的 `<server_slug>__<sanitized_tool>` 形式，覆盖两种场景：
//   - 多个 server 上有同名工具时维持唯一的 function name；
//   - 用户用中文给 MCP server / tool 起名时也能产出合法函数名。
//
// 任何一台 server 连不上 / initialize 失败都只记日志并跳过，让主聊天流程继续——
// 工具属于增量能力，"有就用没就跳过"的策略让主对话路径保持顺畅。
func BuildMcpTools(ctx context.Context, servers []McpServerSpec) *McpToolsHandle {
	handle := &McpToolsHandle{Display: make(map[string]string)}

	for _, server := range servers {
		if len(server.ToolNames) == 0 || strings.TrimSpace(server.EndpointURL) == "" {
			continue
		}

		client, err := buildMcpClient(ctx, server)
		if err != nil {
			log.Printf("ai mcp tools: initialize %s failed: %s", server.Slug, err)
			continue
		}

		rawTools, err := einomcp.GetTools(ctx, &einomcp.Config{
			Cli:          client.Inner(),
			ToolNameList: server.ToolNames,
		})
		if err != nil {
			log.Printf("ai mcp tools: list %s failed: %s", server.Slug, err)
			_ = client.Close()
			continue
		}

		handle.closers = append(handle.closers, client)

		serverLabel := strings.TrimSpace(server.Name)
		if serverLabel == "" {
			serverLabel = server.Slug
		}

		for _, raw := range rawTools {
			invokable := raw.(tool.InvokableTool)
			info, err := invokable.Info(ctx)
			if err != nil {
				log.Printf("ai mcp tools: info %s failed: %s", server.Slug, err)
				continue
			}

			llmName := buildLLMToolName(server.Slug, info.Name)
			handle.Tools = append(handle.Tools, &renamedTool{
				inner:    invokable,
				infoCopy: copyToolInfoWithName(info, llmName),
			})
			handle.Display[llmName] = serverLabel + " / " + info.Name

			if len(handle.Tools) >= maxMcpToolsPerChat {
				return handle
			}
		}
	}

	return handle
}

// buildMcpClient 包装 NewStreamableHTTPClient + Initialize，
// 失败时把 client 立即 Close，让 transport 与读 goroutine 同步回收。
func buildMcpClient(ctx context.Context, spec McpServerSpec) (*mcpclient.Client, error) {
	transport := strings.TrimSpace(spec.Transport)
	if transport == "" {
		transport = "streamable_http"
	}

	bridgeServer := mcpclient.BridgeServer{
		ID:             spec.ID,
		Slug:           spec.Slug,
		Name:           spec.Name,
		Transport:      transport,
		EndpointURL:    spec.EndpointURL,
		Credentials:    spec.Credentials,
		Headers:        spec.Headers,
		TimeoutSeconds: spec.TimeoutSeconds,
	}
	cli, err := mcpclient.NewStreamableHTTPClient(bridgeServer, mcpclient.ResolveTimeout(spec.TimeoutSeconds))
	if err != nil {
		return nil, err
	}
	if err := cli.Initialize(ctx); err != nil {
		_ = cli.Close()
		return nil, err
	}
	return cli, nil
}

// renamedTool 给上游工具换一个 LLM 友好的名字与描述。
//
// 内部把 InvokableRun 直接委托给上游工具，参数 JSON / Schema 保持不变，
// 让 mcp 工具调用的传参 / 返回行为与 eino-ext 直接产出的工具保持一致。
type renamedTool struct {
	inner    tool.InvokableTool
	infoCopy *schema.ToolInfo
}

// Info 返回已经替换过名字的 ToolInfo 副本，参数 Schema 与原工具保持一致。
func (t *renamedTool) Info(_ context.Context) (*schema.ToolInfo, error) {
	return t.infoCopy, nil
}

// InvokableRun 把调用原样委托给内部工具，仅做名字层面的包装，不改变请求/响应内容。
func (t *renamedTool) InvokableRun(ctx context.Context, argumentsInJSON string, opts ...tool.Option) (string, error) {
	return t.inner.InvokableRun(ctx, argumentsInJSON, opts...)
}

// copyToolInfoWithName 复制 ToolInfo 并把 Name 换掉。
// ParamsOneOf 是只读指针语义，沿用原引用即可。
func copyToolInfoWithName(info *schema.ToolInfo, name string) *schema.ToolInfo {
	return &schema.ToolInfo{
		Name:        name,
		Desc:        info.Desc,
		ParamsOneOf: info.ParamsOneOf,
	}
}

// buildLLMToolName 把 (server_slug, mcp_tool_name) 编成 LLM 函数名。
//
// 规则：
//  1. server_slug 在 PHP 侧已经是 Str::slug 的产物（ASCII 且 workspace 内唯一），此处再 sanitize 一遍兜底；
//  2. mcp_tool_name 按 MCP 规范本身就是 identifier，但远端实现质量参差，统一过滤非法字符；
//  3. 用双下划线分隔两段，让排查时一眼看出归属；
//  4. 长度超过 64 时截断，并附 sha1 前 6 位作为唯一性后缀。
func buildLLMToolName(serverSlug, toolName string) string {
	slug := sanitizeIdentifier(serverSlug)
	if slug == "" {
		slug = "mcp"
	}
	tool := sanitizeIdentifier(toolName)
	if tool == "" {
		tool = "tool"
	}
	candidate := slug + "__" + tool

	if len(candidate) <= maxLLMToolNameLength {
		return candidate
	}

	hash := sha1.Sum([]byte(candidate))
	suffix := "_" + hex.EncodeToString(hash[:3]) // 6 个 hex 字符
	body := maxLLMToolNameLength - len(suffix)
	return candidate[:body] + suffix
}

// sanitizeIdentifier 把任意字符串里不合法的字符全换成 `_`，并清掉首尾下划线、连续下划线。
func sanitizeIdentifier(raw string) string {
	if raw == "" {
		return ""
	}
	cleaned := llmToolNameSafeChars.ReplaceAllString(raw, "_")
	for strings.Contains(cleaned, "__") {
		cleaned = strings.ReplaceAll(cleaned, "__", "_")
	}
	return strings.Trim(cleaned, "_-")
}
