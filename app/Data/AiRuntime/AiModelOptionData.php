<?php

namespace App\Data\AiRuntime;

use App\Models\AiModel;
use Spatie\LaravelData\Data;

/**
 * AI 模型可选项。
 * 当前仅用于知识库引擎页 pin 嵌入模型的下拉选项（由 ShowSystemKnowledgeSettingsAction 下发）；
 * 其它取模型场景已统一改走 AiModelPool 按用途路由，不再需要下发模型选项。
 */
class AiModelOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public string $provider_name,
        public string $model_id,
    ) {}

    /**
     * 从 AI 模型构造选项。
     */
    public static function fromModel(AiModel $model): self
    {
        return new self(
            value: (string) $model->id,
            label: $model->name,
            provider_name: $model->provider->name,
            model_id: $model->model_id,
        );
    }
}
