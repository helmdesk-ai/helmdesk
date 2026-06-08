<?php

namespace App\Data\KnowledgeBase;

use App\Enums\KnowledgeChunkingStrategy;
use App\Settings\KnowledgeSettings;
use Spatie\LaravelData\Data;

/**
 * 知识库统一检索配置。
 * 由 ShowSystemKnowledgeSettingsAction 通过 ShowSystemKnowledgeSettingsPagePropsData 下发，
 * 用于 resources/js/pages/systemSettings/knowledgeSettings/Index.vue 的表单回填。
 */
class SystemKnowledgeSettingsData extends Data
{
    public function __construct(
        public ?string $embedding_model_id,
        public ?string $embedding_model_label,
        public ?int $embedding_dimension,
        public bool $vector_index_enabled,
        public bool $raptor_index_enabled,
        public KnowledgeChunkingStrategy $chunking_strategy,
        public int $chunk_max_tokens,
        public int $chunk_overlap_tokens,
    ) {}

    /**
     * 从系统设置构造检索配置。
     * 嵌入模型仍由本页 pin；重排 / 摘要模型改由全局用途池路由，此处不下发。
     */
    public static function fromSettings(KnowledgeSettings $settings): self
    {
        return new self(
            embedding_model_id: filled($settings->embedding_model_id) ? (string) $settings->embedding_model_id : null,
            embedding_model_label: self::modelLabel($settings->embeddingModel()),
            embedding_dimension: $settings->embedding_dimension !== null
                ? (int) $settings->embedding_dimension
                : null,
            vector_index_enabled: (bool) $settings->vector_index_enabled,
            raptor_index_enabled: (bool) $settings->raptor_index_enabled,
            chunking_strategy: $settings->chunkingStrategy(),
            chunk_max_tokens: (int) $settings->chunk_max_tokens,
            chunk_overlap_tokens: (int) $settings->chunk_overlap_tokens,
        );
    }

    /**
     * 拼出 "供应商名称 / 模型名称" 形式的展示标签，模型不存在时返回 null。
     */
    private static function modelLabel(mixed $model): ?string
    {
        if ($model === null || $model->provider === null) {
            return null;
        }

        return $model->provider->name.' / '.$model->name;
    }
}
