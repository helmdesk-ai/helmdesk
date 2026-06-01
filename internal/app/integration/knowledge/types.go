package knowledge

// 请求/响应类型与 PHP 侧 GoKnowledgeBridge 共享 JSON 协议。
//
// 该文件只承担「PHP → Go：调远程模型」一类传输，所以仅保留 embed / summarize 相关类型。

// BridgeProvider 与 ai package 中同名结构镜像，让 knowledge 与 ai 之间保持单向依赖。
// 仅保留 embedder / LLM 构造所需的最小字段。
type BridgeProvider struct {
	Slug             string                  `json:"slug"`
	Name             string                  `json:"name"`
	Protocol         string                  `json:"protocol"`
	Credentials      map[string]string       `json:"credentials"`
	CredentialFields []BridgeCredentialField `json:"credential_fields"`
}

// BridgeCredentialField 描述凭据字段元信息。
type BridgeCredentialField struct {
	Field    string `json:"field"`
	Type     string `json:"type"`
	Required bool   `json:"required"`
}

// BridgeModel 描述目标模型；type 由 PHP 侧保证为 llm / embedding / rerank。
type BridgeModel struct {
	ModelID  string `json:"model_id"`
	Name     string `json:"name"`
	Type     string `json:"type"`
	IsActive bool   `json:"is_active"`
}

// EmbedRequest 是 /knowledge/embed 入参，按 model 批量返回向量。
type EmbedRequest struct {
	Provider BridgeProvider `json:"provider"`
	Model    BridgeModel    `json:"model"`
	Contents []string       `json:"contents"`
}

// EmbedResponse 是 /knowledge/embed 返回 payload。dimension 来自首条向量长度。
type EmbedResponse struct {
	Success    bool        `json:"success"`
	Code       string      `json:"code,omitempty"`
	Message    string      `json:"message,omitempty"`
	Dimension  int         `json:"dimension"`
	Embeddings [][]float64 `json:"embeddings"`
}

// SummarizeRequest 是 /knowledge/summarize 入参，按批量给出摘要文本。
// Batches 每个元素是一组要合并摘要的段，Go 侧负责 prompt 拼装。
type SummarizeRequest struct {
	Provider BridgeProvider `json:"provider"`
	Model    BridgeModel    `json:"model"`
	Batches  [][]string     `json:"batches"`
}

// SummarizeResponse 是 /knowledge/summarize 返回 payload。
type SummarizeResponse struct {
	Success   bool     `json:"success"`
	Code      string   `json:"code,omitempty"`
	Message   string   `json:"message,omitempty"`
	Summaries []string `json:"summaries"`
}

// RerankRequest 是 /knowledge/rerank 入参。
//
// Documents 与 PHP 侧 KnowledgeReranker 传入的 KnowledgeSearchHit.content 数组对齐；
// Go 端返回的 Results 用 index 回指原数组，PHP 端据此把得分写回 hit.metadata['rerank_score']。
type RerankRequest struct {
	Provider  BridgeProvider `json:"provider"`
	Model     BridgeModel    `json:"model"`
	Query     string         `json:"query"`
	Documents []string       `json:"documents"`
	TopN      int            `json:"top_n,omitempty"`
}

// RerankResult 是单条 rerank 输出。
type RerankResult struct {
	Index int     `json:"index"`
	Score float64 `json:"score"`
}

// RerankResponse 是 /knowledge/rerank 返回 payload。
type RerankResponse struct {
	Success bool           `json:"success"`
	Code    string         `json:"code,omitempty"`
	Message string         `json:"message,omitempty"`
	Results []RerankResult `json:"results"`
}

// errorResponse 与 PHP 侧 GoBridgeResponse 风格保持一致，便于翻译。
type errorResponse struct {
	Success bool           `json:"success"`
	Code    string         `json:"code"`
	Params  map[string]any `json:"params,omitempty"`
	Message string         `json:"message"`
}

// 与 PHP 侧 lang/{locale}/knowledge_runtime.php 中的 key 对应。
const (
	codeRequestInvalidPayload = "bridge.request_failed"
	codeEmbedFailed           = "embed.failed"
	codeEmbedModelUnavailable = "embed.model_unavailable"
	codeSummarizeFailed       = "summarize.failed"
	codeSummarizeUnavailable  = "summarize.model_unavailable"
	codeRerankFailed          = "rerank.failed"
	codeRerankUnavailable     = "rerank.model_unavailable"
)
