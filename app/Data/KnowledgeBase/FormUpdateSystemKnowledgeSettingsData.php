<?php

namespace App\Data\KnowledgeBase;

use App\Enums\KnowledgeChunkingStrategy;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 系统知识库统一检索配置表单数据。
 * 来自 resources/js/pages/knowledgeBase/SystemKnowledgeSettingsPanel.vue 的检索配置面板提交。
 */
class FormUpdateSystemKnowledgeSettingsData extends Data
{
    public function __construct(
        public ?string $embedding_model_id = null,
        public ?int $embedding_dimension = null,
        public ?string $rerank_model_id = null,
        public bool $vector_index_enabled = false,
        public bool $raptor_index_enabled = false,
        public KnowledgeChunkingStrategy $chunking_strategy = KnowledgeChunkingStrategy::Fixed,
        public int $chunk_max_tokens = 512,
        public int $chunk_overlap_tokens = 64,
        public ?string $summary_model_id = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'embedding_model_id' => ['nullable', 'string'],
            'embedding_dimension' => ['nullable', 'integer', 'between:1,65535'],
            'rerank_model_id' => ['nullable', 'string'],
            'vector_index_enabled' => ['boolean'],
            'raptor_index_enabled' => ['boolean'],
            'chunking_strategy' => ['nullable', Rule::enum(KnowledgeChunkingStrategy::class), 'required_if:vector_index_enabled,1'],
            'chunk_max_tokens' => ['nullable', 'integer', 'between:64,4096', 'required_if:vector_index_enabled,1'],
            'chunk_overlap_tokens' => ['nullable', 'integer', 'between:0,2048', 'required_if:vector_index_enabled,1'],
            'summary_model_id' => ['nullable', 'string'],
        ];
    }
}
