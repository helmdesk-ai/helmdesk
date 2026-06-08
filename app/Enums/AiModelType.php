<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * AI 模型类型，用来区分 LLM、Embedding 等不同运行能力。
 */
enum AiModelType: string implements LabeledEnum
{
    case Llm = 'llm';
    case Embedding = 'embedding';
    case Rerank = 'rerank';

    public function label(): string
    {
        return match ($this) {
            self::Llm => __('ai.model_types.llm'),
            self::Embedding => __('ai.model_types.embedding'),
            self::Rerank => __('ai.model_types.rerank'),
        };
    }

    /**
     * 该能力类型下允许标注的模型用途。
     *
     * @return list<AiModelPurpose>
     */
    public function purposes(): array
    {
        return AiModelPurpose::forType($this);
    }
}
