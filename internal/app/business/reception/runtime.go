package reception

import (
	"fmt"

	aiintegration "helmdesk/internal/app/integration/ai"
	aittools "helmdesk/internal/app/integration/ai/tools"
)

// runtimeConfig 是 LoadReceptionRuntimeBridgeAction 返回值的 Go 端形态。
type runtimeConfig struct {
	Available                  bool
	Reason                     string
	ConversationID             string
	WorkspaceID                string
	InboxStatus                string
	PlanVersionID              string
	SystemPrompt               string
	PrimaryModel               runtimeModel
	ModelCandidates            []runtimeModel
	PrimaryTaskModel           runtimeModel
	TaskModelCandidates        []runtimeModel
	AiUnavailableNotice        string
	QuoteVisitorMessageEnabled bool
	ServiceScenarios           []serviceScenario
	KnowledgeBases             []planKnowledgeBase
	McpServers                 []aittools.McpServerSpec
}

// runtimeModel 把 PHP 端拍扁后的 provider + model 组合还原到 ai 包认识的形态。
type runtimeModel struct {
	Provider aiintegration.BridgeProvider
	Model    aiintegration.BridgeModel
}

// serviceScenario 是从 PHP 端解码的服务场景条目，供任务 agent 构造指令时使用。
type serviceScenario struct {
	Name         string
	Description  string
	Instructions string
}

// planKnowledgeBase 是接待方案级别的知识库条目，任务 agent 挂 KB 检索工具时使用。
type planKnowledgeBase struct {
	ID          string
	Name        string
	Description string
}

// decodeRuntime 解析 PHP bridge 回来的 map[string]any。
//
// Bridge 总是返回 conversation_id / workspace_id / inbox_status；available=true 时还会带
// plan_version_id / system_prompt / primary_model / primary_task_model。available=false 走 reason 字段决定退出原因。
func decodeRuntime(raw any) (runtimeConfig, error) {
	m, ok := raw.(map[string]any)
	if !ok {
		return runtimeConfig{}, fmt.Errorf("unexpected payload shape: %T", raw)
	}

	cfg := runtimeConfig{
		ConversationID: stringOf(m["conversation_id"]),
		WorkspaceID:    stringOf(m["workspace_id"]),
		InboxStatus:    stringOf(m["inbox_status"]),
	}

	if available, _ := m["available"].(bool); !available {
		cfg.Reason = stringOf(m["reason"])
		return cfg, nil
	}

	cfg.Available = true
	cfg.PlanVersionID = stringOf(m["plan_version_id"])
	cfg.SystemPrompt = stringOf(m["system_prompt"])
	cfg.AiUnavailableNotice = stringOf(m["ai_unavailable_notice"])
	cfg.QuoteVisitorMessageEnabled = boolOfDefault(m["quote_visitor_message_enabled"], true)

	model, err := decodePrimaryModel(m["primary_model"])
	if err != nil {
		return runtimeConfig{}, fmt.Errorf("primary_model: %w", err)
	}
	cfg.PrimaryModel = model

	candidates, err := decodeModelCandidates(m["model_candidates"])
	if err != nil {
		return runtimeConfig{}, fmt.Errorf("model_candidates: %w", err)
	}
	cfg.ModelCandidates = candidates

	taskModel, err := decodePrimaryModel(m["primary_task_model"])
	if err != nil {
		return runtimeConfig{}, fmt.Errorf("primary_task_model: %w", err)
	}
	cfg.PrimaryTaskModel = taskModel

	taskCandidates, err := decodeModelCandidates(m["task_model_candidates"])
	if err != nil {
		return runtimeConfig{}, fmt.Errorf("task_model_candidates: %w", err)
	}
	cfg.TaskModelCandidates = taskCandidates

	cfg.ServiceScenarios = decodeServiceScenarios(m["service_scenarios"])
	cfg.KnowledgeBases = decodePlanKnowledgeBases(m["knowledge_bases"])
	cfg.McpServers = decodePlanMcpServers(m["mcp_servers"])

	return cfg, nil
}

// decodeServiceScenarios 把 PHP bridge 回传的服务场景列表还原成 []serviceScenario。
func decodeServiceScenarios(raw any) []serviceScenario {
	list := anyList(raw)
	out := make([]serviceScenario, 0, len(list))
	for _, item := range list {
		m, ok := item.(map[string]any)
		if !ok {
			continue
		}
		out = append(out, serviceScenario{
			Name:         stringOf(m["name"]),
			Description:  stringOf(m["description"]),
			Instructions: stringOf(m["instructions"]),
		})
	}
	return out
}

// decodePlanKnowledgeBases 把 PHP bridge 回传的知识库快照还原成 []planKnowledgeBase。
// PHP 端 CompileReceptionPlanAction 以索引数组序列化，每项含 id / name / description 字段。
func decodePlanKnowledgeBases(raw any) []planKnowledgeBase {
	list := anyList(raw)
	out := make([]planKnowledgeBase, 0, len(list))
	for _, item := range list {
		entry, ok := item.(map[string]any)
		if !ok {
			continue
		}
		id := stringOf(entry["id"])
		if id == "" {
			continue
		}
		out = append(out, planKnowledgeBase{
			ID:          id,
			Name:        stringOf(entry["name"]),
			Description: stringOf(entry["description"]),
		})
	}
	return out
}

// decodePlanMcpServers 把 PHP bridge 回传的 MCP 服务列表还原成 aitools.McpServerSpec。
// 形态与 chat_stream.McpServerForChat 一致：含 endpoint / 凭据 / header / 启用工具白名单。
func decodePlanMcpServers(raw any) []aittools.McpServerSpec {
	list := anyList(raw)
	out := make([]aittools.McpServerSpec, 0, len(list))
	for _, item := range list {
		entry, ok := item.(map[string]any)
		if !ok {
			continue
		}
		id := stringOf(entry["id"])
		if id == "" {
			continue
		}

		spec := aittools.McpServerSpec{
			ID:             id,
			Slug:           stringOf(entry["slug"]),
			Name:           stringOf(entry["name"]),
			Transport:      stringOf(entry["transport"]),
			EndpointURL:    stringOf(entry["endpoint_url"]),
			Credentials:    stringMap(entry["credentials"]),
			Headers:        stringMap(entry["headers"]),
			TimeoutSeconds: intOf(entry["timeout_seconds"]),
		}

		for _, name := range anyList(entry["tool_names"]) {
			if s := stringOf(name); s != "" {
				spec.ToolNames = append(spec.ToolNames, s)
			}
		}
		if len(spec.ToolNames) == 0 {
			continue
		}

		out = append(out, spec)
	}
	return out
}

// decodeModelCandidates 把 PHP 回传的候选模型列表还原成 []runtimeModel。
// PHP 端已保证 available=true 时一定携带非空列表，类型异常时返回 error 而非静默回退。
func decodeModelCandidates(raw any) ([]runtimeModel, error) {
	if raw == nil {
		return nil, fmt.Errorf("model_candidates is nil")
	}
	list, ok := raw.([]any)
	if !ok {
		return nil, fmt.Errorf("model_candidates has unexpected type: %T", raw)
	}
	if len(list) == 0 {
		return nil, fmt.Errorf("model_candidates is empty")
	}

	candidates := make([]runtimeModel, 0, len(list))
	for i, item := range list {
		model, err := decodePrimaryModel(item)
		if err != nil {
			return nil, fmt.Errorf("candidate[%d]: %w", i, err)
		}
		candidates = append(candidates, model)
	}
	return candidates, nil
}

// decodePrimaryModel 把嵌套 map 还原成 ai 包认识的 BridgeProvider + BridgeModel。
func decodePrimaryModel(raw any) (runtimeModel, error) {
	m, ok := raw.(map[string]any)
	if !ok {
		return runtimeModel{}, fmt.Errorf("missing primary_model")
	}

	providerMap, _ := m["provider"].(map[string]any)
	modelMap, _ := m["model"].(map[string]any)
	if providerMap == nil || modelMap == nil {
		return runtimeModel{}, fmt.Errorf("incomplete primary_model")
	}

	provider := aiintegration.BridgeProvider{
		Slug:        stringOf(providerMap["slug"]),
		Name:        stringOf(providerMap["name"]),
		Protocol:    stringOf(providerMap["protocol"]),
		Credentials: stringMap(providerMap["credentials"]),
	}

	for _, f := range anyList(providerMap["credential_fields"]) {
		field, ok := f.(map[string]any)
		if !ok {
			continue
		}
		provider.CredentialFields = append(provider.CredentialFields, aiintegration.BridgeCredentialField{
			Field:    stringOf(field["field"]),
			Type:     stringOf(field["type"]),
			Required: boolOf(field["required"]),
		})
	}

	model := aiintegration.BridgeModel{
		ModelID:  stringOf(modelMap["model_id"]),
		Name:     stringOf(modelMap["name"]),
		Type:     stringOf(modelMap["type"]),
		IsActive: boolOf(modelMap["is_active"]),
	}

	return runtimeModel{Provider: provider, Model: model}, nil
}

func stringOf(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

func boolOf(v any) bool {
	if b, ok := v.(bool); ok {
		return b
	}
	return false
}

// intOf 兼容 PHP 端 JSON 数字反序列化为 float64 / int / int64 的几种形态。
func intOf(v any) int {
	switch n := v.(type) {
	case int:
		return n
	case int64:
		return int(n)
	case float64:
		return int(n)
	}
	return 0
}

func boolOfDefault(v any, fallback bool) bool {
	if b, ok := v.(bool); ok {
		return b
	}
	return fallback
}

func stringMap(v any) map[string]string {
	m, ok := v.(map[string]any)
	if !ok {
		return nil
	}
	out := make(map[string]string, len(m))
	for k, val := range m {
		if s, ok := val.(string); ok {
			out[k] = s
		}
	}
	return out
}

func anyList(v any) []any {
	if list, ok := v.([]any); ok {
		return list
	}
	return nil
}
