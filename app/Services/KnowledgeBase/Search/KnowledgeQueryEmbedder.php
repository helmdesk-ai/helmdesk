<?php

namespace App\Services\KnowledgeBase\Search;

use App\Models\Workspace;
use App\Services\KnowledgeBase\KnowledgeEmbeddingService;
use Illuminate\Support\Facades\Log;

/**
 * 查询侧嵌入器：把 Agent 给到的多条 query 一次性嵌入。
 *
 * 与索引侧嵌入逻辑共享同一个 KnowledgeEmbeddingService（最终走 GoKnowledgeBridge），
 * 但在这一层做几个使用层的补强：
 *  - 工作区未配置 embedding model 时，直接返回 [0, []]，让 VectorRetriever 自然退化为不检索；
 *  - 入参做去重 + 非空过滤，避免给 Go 桥多余的批次；
 *  - 返回值保持与原始 query 顺序一致（去重后第一次出现的位置）；
 *  - 工作区已落了 knowledge_embedding_dimension 时把它作为可信源，运行时 embed 返回不同维度
 *    意味着模型 API 已经偏离落库时的版本，此时退化为 [0, []]，让 VectorRetriever 退到全文检索，
 *    避免拿新维度向量去比对旧维度的库。
 */
class KnowledgeQueryEmbedder
{
    public function __construct(
        private readonly KnowledgeEmbeddingService $embedder,
    ) {}

    /**
     * 把 workspace 维度的多个 query 一次性嵌入。
     *
     * @param  list<string>  $queries
     * @return array{0: int, 1: list<list<float>>} [dimension, vectors]; queries 为空或模型缺失时返回 [0, []]
     */
    public function embed(Workspace $workspace, array $queries): array
    {
        $workspace->loadMissing('knowledgeEmbeddingModel.provider');
        $model = $workspace->knowledgeEmbeddingModel;
        if ($model === null || $model->provider === null) {
            return [0, []];
        }

        $cleaned = [];
        foreach ($queries as $query) {
            $trimmed = trim((string) $query);
            if ($trimmed === '') {
                continue;
            }
            $cleaned[] = $trimmed;
        }
        if ($cleaned === []) {
            return [0, []];
        }

        [$dimension, $vectors] = $this->embedder->embedTexts($model, $cleaned);

        $expected = $workspace->knowledge_embedding_dimension !== null
            ? (int) $workspace->knowledge_embedding_dimension
            : null;
        if ($expected !== null && $expected > 0 && $dimension !== $expected) {
            Log::warning('Knowledge query embedding dimension mismatch; falling back to non-vector retrieval.', [
                'workspace_id' => (string) $workspace->id,
                'expected_dimension' => $expected,
                'actual_dimension' => $dimension,
            ]);

            return [0, []];
        }

        return [$dimension, array_values($vectors)];
    }
}
