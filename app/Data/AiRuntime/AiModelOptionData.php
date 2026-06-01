<?php

namespace App\Data\AiRuntime;

use App\Models\AiModel;
use Spatie\LaravelData\Data;

/**
 * AI 模型可选项。
 * 由 AiModelResolver 统一下发，覆盖 LLM、Embedding、Rerank 等场景：
 *  - 接待方案版本编辑、AI 浮动助手用到 LLM 列表；
 *  - 知识库创建/编辑用到 Embedding / Rerank 列表。
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
