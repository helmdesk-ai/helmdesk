package mcp

// BridgeRequest 是 PHP 侧 GoMcpRuntimeBridge 发起的请求体。
// PHP 端只关心业务参数，协议握手细节由 Go 端按 server payload 完成。
type BridgeRequest struct {
	Server BridgeServer `json:"server"`
}

// BridgeServer 描述一台 MCP 服务的运行时上下文，仅作为单次请求的载体。
// 协议层把"认证"和"自定义 header"统一视作一组要发的 HTTP header：
//   - Credentials 仅保存认证 header（敏感，PHP 端加密存库），约定结构 {auth_header_name, auth_header_value}；
//   - Headers 保存用户自定义的额外请求头（一般非敏感，例如 X-Workspace-Id 等）。
//
// 是否启用认证完全由 name/value 是否同时非空决定：两者同时非空即写入鉴权 header。
type BridgeServer struct {
	ID             string            `json:"id"`
	Slug           string            `json:"slug"`
	Name           string            `json:"name"`
	Transport      string            `json:"transport"`
	EndpointURL    string            `json:"endpoint_url"`
	Credentials    map[string]string `json:"credentials"`
	Headers        map[string]string `json:"headers"`
	TimeoutSeconds int               `json:"timeout_seconds"`
}

// BridgeResponse 是 Go 侧回给 PHP 的统一响应。
//
// Code 是稳定标识（例如 "check.succeeded"），PHP 侧根据 lang 文件翻译。
// Tools 仅 list-tools 端点会填充。
type BridgeResponse struct {
	Success   bool             `json:"success"`
	Supported bool             `json:"supported"`
	Code      string           `json:"code,omitempty"`
	Params    map[string]any   `json:"params,omitempty"`
	Message   string           `json:"message"`
	Warnings  []string         `json:"warnings,omitempty"`
	Tools     []BridgeToolInfo `json:"tools,omitempty"`
}

// BridgeToolInfo 是远端工具的最小描述，落库到 mcp_tools。
type BridgeToolInfo struct {
	Name        string         `json:"name"`
	Description string         `json:"description,omitempty"`
	InputSchema map[string]any `json:"input_schema,omitempty"`
	Annotations map[string]any `json:"annotations,omitempty"`
}

// MCP 桥接稳定的 Code 常量。与 PHP lang/{locale}/mcp.php 中 runtime.* 键对齐。
const (
	CodeRequestInvalidPayload   = "request.invalid_payload"
	CodeValidateSucceeded       = "validate.succeeded"
	CodeValidateMissingEndpoint = "validate.missing_endpoint"
	CodeValidateUnsupportedTx   = "validate.unsupported_transport"
	CodeCheckSucceeded          = "check.succeeded"
	CodeCheckFailed             = "check.failed"
	CodeCheckTimeout            = "check.timeout"
	CodeCheckUnauthorized       = "check.unauthorized"
	CodeCheckProtocolError      = "check.protocol_error"
	CodeListToolsSucceeded      = "list_tools.succeeded"
	CodeListToolsFailed         = "list_tools.failed"
)
