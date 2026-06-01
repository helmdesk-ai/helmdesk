package ai

// BridgeRequest 是 PHP 调用 AI provider 校验端点时传入的 payload。
type BridgeRequest struct {
	Mode           string         `json:"mode"`
	Provider       BridgeProvider `json:"provider"`
	CandidateModel *BridgeModel   `json:"candidate_model,omitempty"`
}

// BridgeProvider 描述 PHP 侧 AI provider 配置的 Go 端投影。
type BridgeProvider struct {
	Slug             string                  `json:"slug"`
	Name             string                  `json:"name"`
	Brand            string                  `json:"brand"`
	Protocol         string                  `json:"protocol"`
	Credentials      map[string]string       `json:"credentials"`
	CredentialFields []BridgeCredentialField `json:"credential_fields"`
	Models           []BridgeModel           `json:"models"`
}

// BridgeCredentialField 描述 provider 凭据字段。
type BridgeCredentialField struct {
	Field    string `json:"field"`
	Type     string `json:"type"`
	Required bool   `json:"required"`
}

// BridgeModel 描述 PHP 侧 AI model 配置的 Go 端投影。
type BridgeModel struct {
	ModelID  string `json:"model_id"`
	Name     string `json:"name"`
	Type     string `json:"type"`
	IsActive bool   `json:"is_active"`
}

// BridgeResponse 是 Go 侧回给 PHP 的统一响应。
//
// Code 是稳定的消息标识（例如 "check.succeeded"），PHP 侧按 lang 文件翻译为对应语言的文案；
// Params 是翻译插值参数；Message 仅作为英文远端消息 / 日志。
type BridgeResponse struct {
	Success   bool           `json:"success"`
	Code      string         `json:"code,omitempty"`
	Params    map[string]any `json:"params,omitempty"`
	Message   string         `json:"message"`
	Supported bool           `json:"supported"`
	Warnings  []string       `json:"warnings,omitempty"`
}

// 稳定的消息代码。与 PHP lang/{locale}/ai.php 中的 runtime.* 键一一对应。
const (
	CodeRequestInvalidPayload          = "request.invalid_payload"
	CodeValidateUnsupportedMode        = "validate.unsupported_mode"
	CodeValidateCandidateModelRequired = "validate.candidate_model_required"
	CodeValidateMissingCredentials     = "validate.missing_credentials"
	CodeValidateProviderAccepted       = "validate.provider_accepted"
	CodeValidateModelAccepted          = "validate.model_accepted"
	CodeValidateNoActiveModel          = "validate.no_active_model"
	CodeValidateIncompleteCredential   = "validate.incomplete_credentials"
	CodeValidateUnsupported            = "validate.unsupported"
	CodeValidateRuntimeError           = "validate.runtime_error"
	CodeCheckNoActiveLLM               = "check.no_active_llm"
	CodeCheckMissingCredentials        = "check.missing_credentials"
	CodeCheckSucceeded                 = "check.succeeded"
	CodeCheckUnsupported               = "check.unsupported"
	CodeCheckRuntimeError              = "check.runtime_error"
)
