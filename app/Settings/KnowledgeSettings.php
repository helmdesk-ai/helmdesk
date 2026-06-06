<?php

namespace App\Settings;

use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeIndexingStrategy;
use App\Models\AiModel;
use Spatie\LaravelSettings\Settings;

/**
 * 知识库检索与索引设置。
 */
class KnowledgeSettings extends Settings
{
    /**
     * 向量索引使用的 Embedding 模型 ID。
     */
    public ?string $embedding_model_id;

    /**
     * 重排阶段使用的模型 ID。
     */
    public ?string $rerank_model_id;

    /**
     * RAPTOR 摘要阶段使用的 LLM 模型 ID。
     */
    public ?string $summary_model_id;

    /**
     * 当前向量维度。
     */
    public ?int $embedding_dimension;

    /**
     * 是否启用向量索引。
     */
    public bool $vector_index_enabled;

    /**
     * 是否启用 RAPTOR 索引。
     */
    public bool $raptor_index_enabled;

    /**
     * 文档分块策略。
     */
    public string $chunking_strategy;

    /**
     * 单个分块最大 token 数。
     */
    public int $chunk_max_tokens;

    /**
     * 相邻分块重叠 token 数。
     */
    public int $chunk_overlap_tokens;

    /**
     * 返回知识库设置所属的 settings 分组。
     */
    public static function group(): string
    {
        return 'knowledge';
    }

    /**
     * 返回当前启用的知识库索引策略。
     *
     * @return list<KnowledgeIndexingStrategy>
     */
    public function enabledIndexingStrategies(): array
    {
        $strategies = [];

        if ($this->vector_index_enabled) {
            $strategies[] = KnowledgeIndexingStrategy::Vector;
        }

        if ($this->raptor_index_enabled) {
            $strategies[] = KnowledgeIndexingStrategy::Raptor;
        }

        return $strategies;
    }

    /**
     * 判断知识库设置是否启用指定索引策略。
     */
    public function hasIndexingStrategy(KnowledgeIndexingStrategy $strategy): bool
    {
        return in_array($strategy, $this->enabledIndexingStrategies(), true);
    }

    /**
     * 返回文档分块策略枚举。
     */
    public function chunkingStrategy(): KnowledgeChunkingStrategy
    {
        return KnowledgeChunkingStrategy::from($this->chunking_strategy);
    }

    /**
     * 返回配置中的 Embedding 模型。
     */
    public function embeddingModel(): ?AiModel
    {
        return filled($this->embedding_model_id)
            ? AiModel::query()->with('provider')->find($this->embedding_model_id)
            : null;
    }

    /**
     * 返回配置中的重排模型。
     */
    public function rerankModel(): ?AiModel
    {
        return filled($this->rerank_model_id)
            ? AiModel::query()->with('provider')->find($this->rerank_model_id)
            : null;
    }

    /**
     * 返回配置中的摘要模型。
     */
    public function summaryModel(): ?AiModel
    {
        return filled($this->summary_model_id)
            ? AiModel::query()->with('provider')->find($this->summary_model_id)
            : null;
    }
}
