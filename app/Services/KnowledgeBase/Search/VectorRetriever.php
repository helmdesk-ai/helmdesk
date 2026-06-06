<?php

namespace App\Services\KnowledgeBase\Search;

use App\Enums\KnowledgeIndexingStrategy;
use App\Models\KnowledgeNode;
use App\Services\KnowledgeBase\KnowledgeVectorTableManager;
use Illuminate\Support\Collection;

/**
 * 向量召回器。
 *
 * 入参一组 embedding 向量（同一维度），输出一批 KnowledgeSearchHit。流程：
 *  - 先按 kb / strategy / embedding_model_id / embedding_dim 在节点表上取出
 *    本次允许参与召回的 node_id 集合，命中 idx_kn_node_kb_dim 索引；
 *  - 再调用 KnowledgeVectorTableManager::knnSearch 拿候选 (node_id, distance)，KNN 的 k
 *    根据允许集合大小自适应：scope 小时 k 取到 scope 全集，scope 大时按 topK * MULTIPLIER
 *    过取并以 KNN_HARD_CAP 兜底；
 *  - 系统切换 embedding model 后既有向量会被 embedding_model_id 过滤掉，保证距离比较
 *    始终发生在同一模型的向量空间内；
 *  - 每条 query 单独召回，跨 retriever 的结果由 HybridFuser 在上层合并。
 */
class VectorRetriever
{
    /**
     * 允许集合大于 topK 时，候选取 topK * MULTIPLIER。
     */
    private const CANDIDATE_MULTIPLIER = 6;

    /**
     * 单次 KNN 取候选数的硬上限，给非常大的允许集合兜底。
     */
    private const KNN_HARD_CAP = 5000;

    public function __construct(
        private readonly KnowledgeVectorTableManager $vectorTables,
    ) {}

    /**
     * 在指定知识库范围内做向量召回。
     *
     * @param  list<list<float>>  $queryEmbeddings
     * @param  list<string>  $knowledgeBaseIds
     * @param  list<KnowledgeIndexingStrategy>  $strategies  限定 strategy 字段；常见值是 [Text]、[Raptor] 或 [Text, Raptor]
     * @param  string|null  $embeddingModelId  与索引时使用的嵌入模型 id 对齐，null 表示不限制
     * @param  string  $sourceLabel  写入 KnowledgeSearchHit.source 的标签
     * @return list<KnowledgeSearchHit>
     */
    public function retrieve(
        array $knowledgeBaseIds,
        int $dimension,
        array $queryEmbeddings,
        array $strategies,
        int $topK,
        ?string $embeddingModelId = null,
        string $sourceLabel = 'vector',
    ): array {
        if ($dimension <= 0 || $queryEmbeddings === [] || $knowledgeBaseIds === [] || $topK <= 0) {
            return [];
        }
        $strategyValues = array_map(static fn (KnowledgeIndexingStrategy $s) => $s->value, $strategies);
        if ($strategyValues === []) {
            return [];
        }

        $allowedNodes = $this->loadAllowedNodes(
            knowledgeBaseIds: $knowledgeBaseIds,
            strategyValues: $strategyValues,
            dimension: $dimension,
            embeddingModelId: $embeddingModelId,
        );
        if ($allowedNodes->isEmpty()) {
            return [];
        }
        $allowedCount = $allowedNodes->count();

        $k = min(max($topK * self::CANDIDATE_MULTIPLIER, $topK), $allowedCount, self::KNN_HARD_CAP);

        $allHits = [];
        foreach ($queryEmbeddings as $queryIndex => $embedding) {
            $candidates = $this->vectorTables->knnSearch($dimension, $embedding, $k);
            if ($candidates === []) {
                continue;
            }

            $distanceByNode = [];
            foreach ($candidates as $row) {
                $nodeId = (string) $row['node_id'];
                if (! $allowedNodes->has($nodeId)) {
                    continue;
                }
                $distanceByNode[$nodeId] = (float) $row['distance'];
            }
            if ($distanceByNode === []) {
                continue;
            }

            $orderedNodes = $allowedNodes->only(array_keys($distanceByNode))->sortBy(
                static fn (KnowledgeNode $node) => $distanceByNode[$node->id],
            )->values();

            $rank = 0;
            foreach ($orderedNodes as $node) {
                if ($rank >= $topK) {
                    break;
                }
                $rank++;
                $distance = $distanceByNode[$node->id];
                $allHits[] = new KnowledgeSearchHit(
                    source: $sourceLabel,
                    score: $this->distanceToScore($distance),
                    rank: $rank,
                    knowledgeNodeId: $node->id,
                    knowledgeBaseId: $node->knowledge_base_id,
                    documentId: $node->document_id,
                    qaEntryId: $node->qa_entry_id,
                    qaQuestionId: $node->qa_question_id,
                    headingPath: $node->heading_path,
                    byteStart: $node->byte_start,
                    byteEnd: $node->byte_end,
                    content: $node->content,
                    metadata: [
                        'query_index' => $queryIndex,
                        'distance' => $distance,
                        'strategy' => $node->strategy->value,
                        'kind' => $node->kind->value,
                        'level' => $node->level,
                    ],
                );
            }
        }

        return $allHits;
    }

    /**
     * 按 kb / strategy / dim / embedding_model_id 取出本次允许参与召回的节点集合。
     *
     * 这一步把检索 scope 收敛到节点层面：KNN 拿到的候选会跟这个集合做交集，scope 之外的向量
     * 不会进入排序。
     *
     * @param  list<string>  $knowledgeBaseIds
     * @param  list<string>  $strategyValues
     * @return Collection<string, KnowledgeNode>
     */
    private function loadAllowedNodes(
        array $knowledgeBaseIds,
        array $strategyValues,
        int $dimension,
        ?string $embeddingModelId,
    ): Collection {
        $query = KnowledgeNode::query()
            ->whereIn('knowledge_base_id', $knowledgeBaseIds)
            ->whereIn('strategy', $strategyValues)
            ->where('embedding_dim', $dimension);
        if ($embeddingModelId !== null) {
            $query->where('embedding_model_id', $embeddingModelId);
        }

        return $query->get()->keyBy('id');
    }

    /**
     * 距离 → 单调递减相关性得分。sqlite-vec 默认是 L2 距离（>=0），用 1/(1+d) 把得分压到 (0,1]。
     */
    private function distanceToScore(float $distance): float
    {
        return 1.0 / (1.0 + max(0.0, $distance));
    }
}
