package knowledge

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	aiintegration "helmdesk/internal/app/integration/ai"
)

// embedDeadline 是单次 embed 调用的整体上限。
// PHP 侧 GoKnowledgeBridge.EMBED_TIMEOUT_SECONDS 是 90 秒，留 5 秒 buffer。
const embedDeadline = 85 * time.Second

// embedContents 调外部嵌入模型批量生成向量。
// 入口处统一校验凭据 / 内容非空，组装 eino Embedder 后一次性发送。
func embedContents(ctx context.Context, req EmbedRequest) EmbedResponse {
	if req.Model.Type != "embedding" {
		return EmbedResponse{
			Success: false,
			Code:    codeEmbedFailed,
			Message: fmt.Sprintf("model type %q is not an embedding model", req.Model.Type),
		}
	}

	if missing := missingProviderCredentials(req.Provider); len(missing) > 0 {
		return EmbedResponse{
			Success: false,
			Code:    codeEmbedModelUnavailable,
			Message: fmt.Sprintf("missing required credentials: %s", strings.Join(missing, ", ")),
		}
	}

	if len(req.Contents) == 0 {
		return EmbedResponse{Success: true, Dimension: 0, Embeddings: nil}
	}

	bridgeProvider := aiintegration.BridgeProvider{
		Slug:             req.Provider.Slug,
		Name:             req.Provider.Name,
		Protocol:         req.Provider.Protocol,
		Credentials:      req.Provider.Credentials,
		CredentialFields: convertCredentialFields(req.Provider.CredentialFields),
	}

	deadlineCtx, cancel := context.WithTimeout(ctx, embedDeadline)
	defer cancel()

	embedder, err := aiintegration.NewEmbedder(deadlineCtx, bridgeProvider, req.Model.ModelID)
	if err != nil {
		return embedRuntimeFailure(err)
	}

	vectors, err := embedder.EmbedStrings(deadlineCtx, req.Contents)
	if err != nil {
		return embedRuntimeFailure(err)
	}
	if len(vectors) == 0 {
		return EmbedResponse{
			Success: false,
			Code:    codeEmbedFailed,
			Message: "embedder returned no vectors",
		}
	}

	dimension := 0
	for _, v := range vectors {
		if len(v) > 0 {
			dimension = len(v)
			break
		}
	}
	if dimension == 0 {
		return EmbedResponse{
			Success: false,
			Code:    codeEmbedFailed,
			Message: "embedder returned empty vectors",
		}
	}

	return EmbedResponse{
		Success:    true,
		Dimension:  dimension,
		Embeddings: vectors,
	}
}

// embedRuntimeFailure 把 embed 过程中的错误转换为统一的 EmbedResponse：
// 协议或模型类型不支持时归类为 model_unavailable，其余按 embed_failed 上报。
func embedRuntimeFailure(err error) EmbedResponse {
	if errors.Is(err, aiintegration.ErrUnsupportedProtocol) || errors.Is(err, aiintegration.ErrUnsupportedModelType) {
		return EmbedResponse{
			Success: false,
			Code:    codeEmbedModelUnavailable,
			Message: err.Error(),
		}
	}
	return EmbedResponse{
		Success: false,
		Code:    codeEmbedFailed,
		Message: err.Error(),
	}
}

// missingProviderCredentials 返回 provider 中所有必填但缺失（或全空白）的凭据字段名，
// 用于在真正调用上游前给出明确的错误码与提示。
func missingProviderCredentials(provider BridgeProvider) []string {
	missing := make([]string, 0)
	for _, field := range provider.CredentialFields {
		if !field.Required {
			continue
		}
		if strings.TrimSpace(provider.Credentials[field.Field]) == "" {
			missing = append(missing, field.Field)
		}
	}
	return missing
}

// convertCredentialFields 把本包定义的 BridgeCredentialField 转成 ai integration 包对应的类型，
// 让上层只看到本包的字段定义，保持包边界清晰。
func convertCredentialFields(fields []BridgeCredentialField) []aiintegration.BridgeCredentialField {
	out := make([]aiintegration.BridgeCredentialField, 0, len(fields))
	for _, f := range fields {
		out = append(out, aiintegration.BridgeCredentialField{
			Field:    f.Field,
			Type:     f.Type,
			Required: f.Required,
		})
	}
	return out
}
