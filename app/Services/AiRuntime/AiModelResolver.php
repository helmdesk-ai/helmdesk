<?php

namespace App\Services\AiRuntime;

use App\Enums\AiModelType;
use App\Models\AiModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 知识库引擎页校验 pin 的嵌入模型仍可用。
 *
 * 接待 / 会话 / 助手等运行时取模型已统一改走 AiModelPool（按用途池路由）；
 * 这里只保留知识库「嵌入模型 pin」这一处仍需的「按 type 校验启用模型」的能力。
 */
class AiModelResolver
{
    /**
     * 校验并取得启用供应商下的启用模型；不存在 / 已停用时抛字段级验证异常。
     */
    public function resolveActiveKnowledgeBaseModel(string $modelId, AiModelType $type, string $field): AiModel
    {
        $model = $this->activeSystemModelsQuery($type)
            ->whereKey($modelId)
            ->first();

        if ($model !== null) {
            return $model;
        }

        throw ValidationException::withMessages([
            $field => match ($type) {
                AiModelType::Embedding => __('knowledge_base.messages.invalid_embedding_model'),
                AiModelType::Rerank => __('knowledge_base.messages.invalid_rerank_model'),
                AiModelType::Llm => __('knowledge_base.messages.invalid_summary_model'),
            },
        ]);
    }

    /**
     * 启用模型的统一查询（供应商存在即可用），按模型 sort_order 排序。
     */
    private function activeSystemModelsQuery(AiModelType $type): Builder
    {
        return AiModel::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->whereHas('provider')
            ->with('provider')
            ->orderBy('sort_order');
    }
}
