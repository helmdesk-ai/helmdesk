<?php

namespace App\Data\AiModel;

use App\Enums\AiModelPurpose;
use App\Enums\AiModelType;
use App\Models\AiModel;
use Spatie\LaravelData\Data;

/**
 * AI 模型管理页的模型列表项（一行=一个模型+一个用途）。
 *
 * 由 ShowAiModelListAction 组装，传给 resources/js/pages/systemSettings/aiModels/List.vue：按 purpose 分 Tab，
 * Tab 内按 sort_order 排序（上移/下移调主备）。
 */
class AiModelListItemData extends Data
{
    public function __construct(
        public string $id,
        public string $model_id,
        public string $name,
        public AiModelType $type,
        public string $type_label,
        public AiModelPurpose $purpose,
        public string $purpose_label,
        public string $ai_provider_id,
        public string $provider_name,
        public bool $is_active,
        public int $sort_order,
    ) {}

    /**
     * 从模型（需预载 provider）构造列表项。
     */
    public static function fromModel(AiModel $model): self
    {
        $type = $model->type instanceof AiModelType ? $model->type : AiModelType::from((string) $model->type);
        $purpose = $model->purpose instanceof AiModelPurpose ? $model->purpose : AiModelPurpose::from((string) $model->purpose);

        return new self(
            id: $model->id,
            model_id: $model->model_id,
            name: $model->name,
            type: $type,
            type_label: $type->label(),
            purpose: $purpose,
            purpose_label: $purpose->label(),
            ai_provider_id: $model->ai_provider_id,
            provider_name: $model->provider->name ?? '',
            is_active: $model->is_active,
            sort_order: $model->sort_order,
        );
    }
}
