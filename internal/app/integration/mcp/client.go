package mcp

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"strings"
	"time"

	mcpgoclient "github.com/mark3labs/mcp-go/client"
	mcpgotransport "github.com/mark3labs/mcp-go/client/transport"
	mcpgo "github.com/mark3labs/mcp-go/mcp"
)

// 与 MCP server 单次交互的默认超时上限。
const defaultTimeout = 30 * time.Second

// 客户端层错误，转回 BridgeResponse 时按类型映射稳定 code。
var (
	ErrTimeout      = errors.New("mcp: request timeout")
	ErrUnauthorized = errors.New("mcp: server rejected authentication")
	ErrProtocol     = errors.New("mcp: protocol error")
)

// Client 是一台 MCP server 的"单连接生命周期"封装。
//
// 用法固定为：
//
//	cli, err := NewStreamableHTTPClient(server, timeout)
//	defer cli.Close()
//	cli.Initialize(ctx)
//	cli.ListTools(ctx)
//
// 内部直接使用社区 mark3labs/mcp-go 客户端，所以协议层握手 / session id / SSE 解码
// 都交给它去做。我们只在边界处把错误归类成本包稳定的 Err* 哨兵。
//
// Inner 返回底层 mcp-go 客户端实例，供 eino-ext/components/tool/mcp 直接消费。
type Client struct {
	inner *mcpgoclient.Client
}

// NewStreamableHTTPClient 按 BridgeServer 构造一个 Streamable HTTP 客户端并完成 Start。
// 调用方拿到的客户端处于"已连接但未初始化"状态，必须随后调用 Initialize 才能发请求。
func NewStreamableHTTPClient(server BridgeServer, timeout time.Duration) (*Client, error) {
	if timeout <= 0 {
		timeout = defaultTimeout
	}

	opts := []mcpgotransport.StreamableHTTPCOption{
		mcpgotransport.WithHTTPTimeout(timeout),
	}
	if headers := composeHeaders(server); len(headers) > 0 {
		opts = append(opts, mcpgotransport.WithHTTPHeaders(headers))
	}

	cli, err := mcpgoclient.NewStreamableHttpClient(server.EndpointURL, opts...)
	if err != nil {
		return nil, classifyError(err)
	}

	startCtx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	if err := cli.Start(startCtx); err != nil {
		_ = cli.Close()
		return nil, classifyError(err)
	}

	return &Client{inner: cli}, nil
}

// Initialize 完成 MCP 协议握手，并随后由 mcp-go 自动发出 notifications/initialized。
// 失败时返回归类后的稳定错误，调用方按 Err* 哨兵判断。
func (c *Client) Initialize(ctx context.Context) error {
	req := mcpgo.InitializeRequest{}
	req.Params.ProtocolVersion = mcpgo.LATEST_PROTOCOL_VERSION
	req.Params.ClientInfo = mcpgo.Implementation{
		Name:    "helmdesk",
		Version: "0.1.0",
	}
	req.Params.Capabilities = mcpgo.ClientCapabilities{}

	if _, err := c.inner.Initialize(ctx, req); err != nil {
		return classifyError(err)
	}
	return nil
}

// ListTools 从远端 server 拉取工具列表，归一化为 BridgeToolInfo。
// 远端字段中只保留我们落库需要的部分（name / description / input_schema / annotations）。
func (c *Client) ListTools(ctx context.Context) ([]BridgeToolInfo, error) {
	resp, err := c.inner.ListTools(ctx, mcpgo.ListToolsRequest{})
	if err != nil {
		return nil, classifyError(err)
	}

	out := make([]BridgeToolInfo, 0, len(resp.Tools))
	for _, t := range resp.Tools {
		out = append(out, BridgeToolInfo{
			Name:        t.Name,
			Description: t.Description,
			InputSchema: marshalToMap(t.InputSchema),
			Annotations: marshalToMap(t.Annotations),
		})
	}
	return out, nil
}

// Inner 暴露底层 mcp-go 客户端，给 eino-ext/components/tool/mcp 直接使用。
// 注意：Inner 只在调用方持有 Client 引用期间安全，Close 后再访问会落到已关闭的 transport。
func (c *Client) Inner() *mcpgoclient.Client {
	return c.inner
}

// Close 关闭底层 transport。重复调用安全（mcp-go 内部会忽略）。
func (c *Client) Close() error {
	return c.inner.Close()
}

// composeHeaders 把 Bridge 下发的自定义 header 与认证 header 合并成一份大写规范化的 map。
// 协议层把认证视作一对 {auth_header_name, auth_header_value}：同时非空即写入；
// 上层 UI 上的“Bearer Token”快捷模板由前端拼出 Authorization=Bearer <token>。
func composeHeaders(server BridgeServer) map[string]string {
	headers := map[string]string{}

	for name, value := range server.Headers {
		if name == "" || value == "" {
			continue
		}
		headers[name] = value
	}

	authName := strings.TrimSpace(server.Credentials["auth_header_name"])
	authValue := strings.TrimSpace(server.Credentials["auth_header_value"])
	if authName != "" && authValue != "" {
		headers[authName] = authValue
	}

	return headers
}

// marshalToMap 把任意结构体经 JSON 序列化后转成 map[string]any，方便存到 BridgeToolInfo。
// 失败或空结构都返回 nil，让 PHP 端落库时直接写 NULL 而非 `{}`。
func marshalToMap(v any) map[string]any {
	bytes, err := json.Marshal(v)
	if err != nil {
		log.Printf("mcp marshalToMap: json.Marshal failed: %v", err)
		return nil
	}
	if len(bytes) == 0 || string(bytes) == "null" || string(bytes) == "{}" {
		return nil
	}
	out := map[string]any{}
	if err := json.Unmarshal(bytes, &out); err != nil {
		log.Printf("mcp marshalToMap: json.Unmarshal failed: %v", err)
		return nil
	}
	if len(out) == 0 {
		return nil
	}
	return out
}

// classifyError 把 mcp-go 抛出的错误归类成稳定哨兵，便于上层映射 code。
// mcp-go 已经对常见情形提供了类型化错误：context 超时走 errors.Is，401/OAuth 走 errors.As。
// 其它一切包成 ErrProtocol。
func classifyError(err error) error {
	if err == nil {
		return nil
	}
	if errors.Is(err, context.DeadlineExceeded) {
		return ErrTimeout
	}

	var authErr *mcpgotransport.AuthorizationRequiredError
	if errors.As(err, &authErr) {
		return fmt.Errorf("%w: %s", ErrUnauthorized, err.Error())
	}
	var oauthErr *mcpgotransport.OAuthAuthorizationRequiredError
	if errors.As(err, &oauthErr) {
		return fmt.Errorf("%w: %s", ErrUnauthorized, err.Error())
	}

	return fmt.Errorf("%w: %s", ErrProtocol, err.Error())
}
