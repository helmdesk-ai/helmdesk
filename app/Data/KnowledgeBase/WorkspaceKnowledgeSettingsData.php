<?php

namespace App\Data\KnowledgeBase;

use App\Enums\KnowledgeChunkingStrategy;
use App\Models\Workspace;
use Spatie\LaravelData\Data;

/**
 * 工作区知识库统一检索配置。
 * 由 ListKnowledgeBasesAction 通过 ShowKnowledgeBaseListPagePropsData 下发，
 * 用于 resources/js/pages/knowledgeBase/WorkspaceKnowledgeSettingsPanel.vue 的表单回填。
 */
class WorkspaceKnowledgeSettingsData extends Data
{
    public function __construct(
        public ?string $embedding_model_id,
        public ?string $embedding_model_label,
        public ?int $embedding_dimension,
        public ?string $rerank_model_id,
        public ?string $rerank_model_label,
        public ?string $summary_model_id,
        public ?string $summary_model_label,
        public bool $vector_index_enabled,
        public bool $raptor_index_enabled,
        public KnowledgeChunkingStrategy $chunking_strategy,
        public int $chunk_max_tokens,
        public int $chunk_overlap_tokens,
    ) {}

    /**
     * 从工作区模型构造检索配置。
     */
    public static function fromWorkspace(Workspace $workspace): self
    {
        return new self(
            embedding_model_id: filled($workspace->knowledge_embedding_model_id) ? (string) $workspace->knowledge_embedding_model_id : null,
            embedding_model_label: self::modelLabel($workspace, 'knowledgeEmbeddingModel'),
            embedding_dimension: $workspace->knowledge_embedding_dimension !== null
                ? (int) $workspace->knowledge_embedding_dimension
                : null,
            rerank_model_id: filled($workspace->knowledge_rerank_model_id) ? (string) $workspace->knowledge_rerank_model_id : null,
            rerank_model_label: self::modelLabel($workspace, 'knowledgeRerankModel'),
            summary_model_id: filled($workspace->knowledge_summary_model_id) ? (string) $workspace->knowledge_summary_model_id : null,
            summary_model_label: self::modelLabel($workspace, 'knowledgeSummaryModel'),
            vector_index_enabled: (bool) $workspace->knowledge_vector_index_enabled,
            raptor_index_enabled: (bool) $workspace->knowledge_raptor_index_enabled,
            chunking_strategy: $workspace->knowledge_chunking_strategy,
            chunk_max_tokens: (int) $workspace->knowledge_chunk_max_tokens,
            chunk_overlap_tokens: (int) $workspace->knowledge_chunk_overlap_tokens,
        );
    }

    /**
     * 拼出 "供应商名称 / 模型名称" 形式的展示标签，关系未加载或字段为空时返回 null。
     */
    private static function modelLabel(Workspace $workspace, string $relation): ?string
    {
        $model = $workspace->{$relation};
        if ($model === null || $model->provider === null) {
            return null;
        }

        return $model->provider->name.' / '.$model->name;
    }
}
