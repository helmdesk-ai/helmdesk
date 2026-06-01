<?php

namespace App\Data\AiProvider;

use App\Models\AiModel;
use Spatie\LaravelData\Data;

/**
 * AI模型数据。
 * 由后端组装后传给 resources/js/pages/workspaceSettings/aiProviders/Index.vue，
 * 用于页面展示供应商下的模型标识、类型和启用状态。
 */
class AiModelData extends Data
{
    public function __construct(
        public string $id,
        public string $ai_provider_id,
        public string $model_id,
        public string $name,
        public string $type,
        public bool $is_active,
        public bool $is_builtin,
        public int $sort_order,
    ) {}

    /**
     * 从 Eloquent 模型构造 AiModelData，用于前端模型列表展示。
     */
    public static function fromModel(AiModel $model): self
    {
        return new self(
            id: $model->id,
            ai_provider_id: $model->ai_provider_id,
            model_id: $model->model_id,
            name: $model->name,
            type: $model->type,
            is_active: $model->is_active,
            is_builtin: $model->is_builtin,
            sort_order: $model->sort_order,
        );
    }
}
