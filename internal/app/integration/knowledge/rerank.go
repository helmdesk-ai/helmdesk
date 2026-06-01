package knowledge

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// rerankDeadline 是单次 rerank 调用整体上限。
// 与 PHP 侧 GoKnowledgeBridge.RERANK_TIMEOUT_SECONDS 对齐（30 秒），留点 buffer。
const rerankDeadline = 28 * time.Second

// rerankContents 把若干候选 document 与 query 一起送到外部 rerank 模型。
//
// rerank 走与 chat 无关的独立 HTTP 通道：凭据里配了 base_uri 的供应商，以其为基址走 Cohere 式
// `/rerank` 协议（POST {base}/rerank，{model, query, documents, top_n} → {results:[{index, relevance_score}]}）。
// 没有 base_uri 则返回 model_unavailable，PHP 侧 KnowledgeReranker 会优雅降级为不重排。
func rerankContents(ctx context.Context, req RerankRequest) RerankResponse {
	if req.Model.Type != "rerank" {
		return RerankResponse{
			Success: false,
			Code:    codeRerankFailed,
			Message: fmt.Sprintf("model type %q is not a rerank model", req.Model.Type),
		}
	}

	if missing := missingProviderCredentials(req.Provider); len(missing) > 0 {
		return RerankResponse{
			Success: false,
			Code:    codeRerankUnavailable,
			Message: fmt.Sprintf("missing required credentials: %s", strings.Join(missing, ", ")),
		}
	}

	if strings.TrimSpace(req.Query) == "" || len(req.Documents) == 0 {
		return RerankResponse{Success: true, Results: nil}
	}

	baseURI := strings.TrimSpace(req.Provider.Credentials["base_uri"])
	if baseURI == "" {
		return RerankResponse{
			Success: false,
			Code:    codeRerankUnavailable,
			Message: "provider has no base_uri configured for rerank",
		}
	}

	deadlineCtx, cancel := context.WithTimeout(ctx, rerankDeadline)
	defer cancel()

	results, err := callCohereStyleRerank(deadlineCtx, req, strings.TrimRight(baseURI, "/")+"/rerank")
	if err != nil {
		return rerankRuntimeFailure(err)
	}

	return RerankResponse{Success: true, Results: results}
}

// callCohereStyleRerank 实现 cohere/jina 类的标准 rerank JSON 协议：
//
//	POST <url>
//	Authorization: Bearer <api_key>
//	{"model": "...", "query": "...", "documents": [...], "top_n": 10}
//	→
//	{"results": [{"index": 0, "relevance_score": 0.9}, ...]}
func callCohereStyleRerank(ctx context.Context, req RerankRequest, url string) ([]RerankResult, error) {
	payload := map[string]any{
		"model":     req.Model.ModelID,
		"query":     req.Query,
		"documents": req.Documents,
	}
	if req.TopN > 0 {
		payload["top_n"] = req.TopN
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	httpReq.Header.Set("Content-Type", "application/json")
	if apiKey := strings.TrimSpace(req.Provider.Credentials["key"]); apiKey != "" {
		httpReq.Header.Set("Authorization", "Bearer "+apiKey)
	}

	resp, err := http.DefaultClient.Do(httpReq)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, err
	}
	if resp.StatusCode/100 != 2 {
		return nil, fmt.Errorf("rerank upstream returned status %d: %s", resp.StatusCode, strings.TrimSpace(string(raw)))
	}

	var decoded struct {
		Results []struct {
			Index          int     `json:"index"`
			RelevanceScore float64 `json:"relevance_score"`
		} `json:"results"`
	}
	if err := json.Unmarshal(raw, &decoded); err != nil {
		return nil, fmt.Errorf("rerank upstream returned unparsable response: %w", err)
	}

	results := make([]RerankResult, 0, len(decoded.Results))
	for _, item := range decoded.Results {
		results = append(results, RerankResult{Index: item.Index, Score: item.RelevanceScore})
	}
	return results, nil
}

// rerankRuntimeFailure 把 rerank 调用中的错误归类成 RerankResponse：
// 上下文超时算 rerank_unavailable（PHP 侧会优雅降级），其余按 rerank_failed 上报。
func rerankRuntimeFailure(err error) RerankResponse {
	if errors.Is(err, context.DeadlineExceeded) {
		return RerankResponse{
			Success: false,
			Code:    codeRerankUnavailable,
			Message: err.Error(),
		}
	}
	return RerankResponse{
		Success: false,
		Code:    codeRerankFailed,
		Message: err.Error(),
	}
}
