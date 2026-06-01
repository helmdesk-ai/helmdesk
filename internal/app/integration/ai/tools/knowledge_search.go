package tools

import (
	"context"
	"fmt"
	"strings"
	"unicode/utf8"

	"github.com/cloudwego/eino/components/tool"
	"github.com/cloudwego/eino/components/tool/utils"
	"github.com/dunglas/frankenphp"

	"helmdesk/internal/app/phpbridge"
)

// 暴露给 LLM 的工具名。PHP 端 KnowledgeSearchBridgeAction 与前端日志直接引用此字面量，
// 调整时同步关注 Mercure 上的 tool_call / tool_result 事件展示。
const knowledgeSearchToolName = "knowledge_search"

// 单次工具调用允许的最大 query 条数，与 PHP 端 FormKnowledgeSearchData::MAX_QUERIES 保持一致。
// grep 模式每条 query 都会扫一遍 parsed_content，按上限收敛单次调用成本；
// 超过上限时 Go 侧直接返回错误，让 LLM 收到明确反馈再自己拆分。
const knowledgeSearchMaxQueries = 8

// 单条 query 最大字符长度（rune 计数），和 PHP 端 FormKnowledgeSearchData::MAX_QUERY_LENGTH 对齐。
// 超长 query 通常意味着 LLM 把整段 prompt 塞进来，截断后让 FTS5 表达式 token 量保持稳定。
const knowledgeSearchMaxQueryLength = 200

// KnowledgeBaseSpec 是构造工具时绑定的"本对话允许检索的知识库"白名单条目。
//
// 数据来源于 PHP 端 CollectActiveKnowledgeBasesAction，Go 仅做字段透传 + 文案拼装。
type KnowledgeBaseSpec struct {
	ID          string
	Name        string
	Description string
}

// KnowledgeSearchInput 是 LLM 实际调用 knowledge_search 时填的字段。
//
// 暴露的字段只有 2 个，遵循"参数尽量少"的设计原则：
//   - Mode：检索方式；
//   - Query：单次工具调用可以传多个词或句子，PHP 端会按 query 维度做检索后合并。
//
// 注意：workspaceID 和 knowledge_base_ids 均由工具构造阶段绑定到闭包，对 LLM 完全透明；
// 这样 LLM 不需要知道也无法绕过允许列表；top_k / 重排 / RAPTOR 等参数也由服务端按 workspace 配置决定。
type KnowledgeSearchInput struct {
	Mode  string   `json:"mode" jsonschema:"enum=grep,enum=semantic,enum=hybrid,description=Retrieval mode. grep: literal case-insensitive substring matching with line/column info. semantic: vector + full-text + optional RAPTOR + optional rerank, all governed by workspace settings. hybrid: both grep and semantic results returned as two parallel arrays."`
	Query []string `json:"query" jsonschema:"description=One or more search queries (1-8 items, up to 200 characters each). Multiple queries are merged on the server side; supply 1-4 distinct phrasings or angles when in doubt."`
}

// KnowledgeSearchOutput 直接照搬 PHP 端 KnowledgeSearchResultData 的 JSON 形态：
//   - Mode 回显本次调用的检索方式；
//   - SemanticHits 来自 vector / fulltext / RAPTOR / rerank，结构对齐 KnowledgeSearchHit；
//   - GrepMatches 来自类 grep 字面匹配，带 line/column/byte_offset/context；
//   - Debug 含 vector/raptor/rerank 是否启用、各路 retriever 命中数等可观测字段，便于排错。
type KnowledgeSearchOutput struct {
	Mode         string           `json:"mode"`
	SemanticHits []map[string]any `json:"semantic_hits"`
	GrepMatches  []map[string]any `json:"grep_matches"`
	Debug        map[string]any   `json:"debug,omitempty"`
}

// NewKnowledgeSearchTool 构造一个绑定了 workspace 与可用知识库白名单的 knowledge_search 工具。
//
//   - workspaceID 必填：从 ChatStreamRequest 透传过来，PHP 端会再次校验访问权限；
//   - knowledgeBases 必须至少一项：调用方需在调用前判断；空列表会让工具描述缺失白名单段落，
//     LLM 会失去检索目标，因此构造阶段直接拒绝；
//   - workers 必填：实际是 frankenphp.Workers，knowledge_search 通过它反向调 PHP Bridge Action。
//
// 构造工具是同步路径，刻意保持轻量：知识库列表由 PHP→Go 桥接请求一次性带过来，
// 构造阶段直接复用，省去额外的 PHP 调用。
func NewKnowledgeSearchTool(workspaceID string, knowledgeBases []KnowledgeBaseSpec, workers frankenphp.Workers) (tool.InvokableTool, error) {
	workspaceID = strings.TrimSpace(workspaceID)
	if workspaceID == "" {
		return nil, fmt.Errorf("knowledge_search: workspace_id is required")
	}
	if len(knowledgeBases) == 0 {
		return nil, fmt.Errorf("knowledge_search: at least one knowledge base must be provided")
	}
	if workers == nil {
		return nil, fmt.Errorf("knowledge_search: native workers are required")
	}

	allowed := make(map[string]struct{}, len(knowledgeBases))
	for _, kb := range knowledgeBases {
		id := strings.TrimSpace(kb.ID)
		if id == "" {
			continue
		}
		allowed[id] = struct{}{}
	}
	if len(allowed) == 0 {
		return nil, fmt.Errorf("knowledge_search: no usable knowledge base ids")
	}

	desc := buildKnowledgeSearchDescription(knowledgeBases)

	invoker := func(ctx context.Context, input KnowledgeSearchInput) (KnowledgeSearchOutput, error) {
		return invokeKnowledgeSearch(workspaceID, allowed, workers, input)
	}

	return utils.InferTool(knowledgeSearchToolName, desc, invoker)
}

// invokeKnowledgeSearch 真正执行工具：参数检查 → 调 PHP Bridge → 把响应映射为 KnowledgeSearchOutput。
//
// 拆出独立函数有两个考虑：
//  1. 构造阶段（utils.InferTool）只跑一次 build allowed set，运行时直接复用结果；
//  2. 便于单测：直接喂 KnowledgeSearchInput 即可，单测路径独立于 utils.InferTool 流水。
//
// KnowledgeBaseIDs 不暴露给 LLM：每次调用都传入全部 allowedKnowledgeBaseIDs，PHP 端负责范围限定。
func invokeKnowledgeSearch(
	workspaceID string,
	allowedKnowledgeBaseIDs map[string]struct{},
	workers frankenphp.Workers,
	input KnowledgeSearchInput,
) (KnowledgeSearchOutput, error) {
	mode := strings.ToLower(strings.TrimSpace(input.Mode))
	switch mode {
	case "grep", "semantic", "hybrid":
	default:
		return KnowledgeSearchOutput{}, fmt.Errorf("invalid mode %q: expected one of grep / semantic / hybrid", input.Mode)
	}

	// 始终使用闭包绑定的允许列表，不依赖 LLM 传参。
	cleanedIDs := make([]string, 0, len(allowedKnowledgeBaseIDs))
	for id := range allowedKnowledgeBaseIDs {
		cleanedIDs = append(cleanedIDs, id)
	}

	cleanedQueries := make([]string, 0, len(input.Query))
	for _, q := range input.Query {
		trimmed := strings.TrimSpace(q)
		if trimmed == "" {
			continue
		}
		// 字符长度按 rune 计数，与 PHP 端 mb_strlen 对齐；超长直接拒绝，让 LLM 自行修正。
		if utf8.RuneCountInString(trimmed) > knowledgeSearchMaxQueryLength {
			return KnowledgeSearchOutput{}, fmt.Errorf(
				"query item too long: max %d characters per query",
				knowledgeSearchMaxQueryLength,
			)
		}
		cleanedQueries = append(cleanedQueries, trimmed)
	}
	if len(cleanedQueries) == 0 {
		return KnowledgeSearchOutput{}, fmt.Errorf("query must contain at least one non-empty string")
	}
	if len(cleanedQueries) > knowledgeSearchMaxQueries {
		return KnowledgeSearchOutput{}, fmt.Errorf(
			"too many queries: at most %d allowed per call",
			knowledgeSearchMaxQueries,
		)
	}

	// 转成 []any 让 phpbridge 的 normalize 走 slice 分支。
	queryParams := make([]any, len(cleanedQueries))
	for i, q := range cleanedQueries {
		queryParams[i] = q
	}
	kbParams := make([]any, len(cleanedIDs))
	for i, id := range cleanedIDs {
		kbParams[i] = id
	}

	raw, err := phpbridge.CallNative(
		workers,
		`App\Actions\Native\Knowledge\KnowledgeSearchBridgeAction`,
		workspaceID,
		mode,
		kbParams,
		queryParams,
	)
	if err != nil {
		if bridgeErr := phpbridge.AsBridgeError(err); bridgeErr != nil {
			return KnowledgeSearchOutput{}, fmt.Errorf("knowledge search failed: %s", bridgeErr.Message)
		}
		return KnowledgeSearchOutput{}, fmt.Errorf("knowledge search failed: %w", err)
	}

	out, err := mapKnowledgeSearchResult(raw)
	if err != nil {
		return KnowledgeSearchOutput{}, fmt.Errorf("knowledge search bridge returned unexpected payload: %w", err)
	}
	return out, nil
}

// mapKnowledgeSearchResult 把 PHP Bridge 返回的 map[string]any 投影成 KnowledgeSearchOutput。
// Bridge 出参的结构已经在 PHP 端通过 KnowledgeSearchResultData 严格定义，这里直接按预期形态拆。
func mapKnowledgeSearchResult(raw any) (KnowledgeSearchOutput, error) {
	asMap, ok := raw.(map[string]any)
	if !ok {
		return KnowledgeSearchOutput{}, fmt.Errorf("expected map, got %T", raw)
	}

	out := KnowledgeSearchOutput{
		SemanticHits: []map[string]any{},
		GrepMatches:  []map[string]any{},
		Debug:        map[string]any{},
	}

	if mode, ok := asMap["mode"].(string); ok {
		out.Mode = mode
	}
	if hits, ok := asMap["semantic_hits"].([]any); ok {
		out.SemanticHits = toObjectList(hits)
	}
	if matches, ok := asMap["grep_matches"].([]any); ok {
		out.GrepMatches = toObjectList(matches)
	}
	if debug, ok := asMap["debug"].(map[string]any); ok {
		out.Debug = debug
	}

	return out, nil
}

// toObjectList 把 []any 列表按 map[string]any 元素逐项收集。
func toObjectList(items []any) []map[string]any {
	out := make([]map[string]any, len(items))
	for i, item := range items {
		out[i] = item.(map[string]any)
	}
	return out
}

// buildKnowledgeSearchDescription 渲染工具描述，把可用知识库列表写进去，方便 LLM 自行挑选。
//
// 描述主体使用英文，让 OpenAI / Anthropic 等模型的函数理解保持最稳；
// KB 的 name / description 保留原文，让中文用户在工具描述里能看到自己熟悉的库名。
func buildKnowledgeSearchDescription(knowledgeBases []KnowledgeBaseSpec) string {
	var b strings.Builder
	b.WriteString("Search the workspace knowledge bases. ")
	b.WriteString("Use this tool whenever the user's question may be answerable from internal documents, FAQs, or QA entries. ")
	b.WriteString("Pick a mode:\n")
	b.WriteString("- grep: literal case-insensitive substring matching (best for IDs / codes / verbatim phrases).\n")
	b.WriteString("- semantic: vector + full-text + workspace-configured RAPTOR & rerank (best for natural-language questions).\n")
	b.WriteString("- hybrid: run both grep and semantic; results are returned as two separate arrays for you to compare.\n")
	b.WriteString("\nProvide one or more query strings (use multiple distinct phrasings to broaden recall in a single call).\n")
	b.WriteString("All available knowledge bases are searched automatically; you do not need to specify IDs.\n")
	b.WriteString("\nAvailable knowledge bases:\n")
	for _, kb := range knowledgeBases {
		id := strings.TrimSpace(kb.ID)
		if id == "" {
			continue
		}
		name := strings.TrimSpace(kb.Name)
		if name == "" {
			name = id
		}
		desc := strings.TrimSpace(kb.Description)
		b.WriteString("- ")
		b.WriteString(id)
		b.WriteString(" — ")
		b.WriteString(name)
		if desc != "" {
			b.WriteString(": ")
			b.WriteString(desc)
		}
		b.WriteString("\n")
	}
	return b.String()
}
