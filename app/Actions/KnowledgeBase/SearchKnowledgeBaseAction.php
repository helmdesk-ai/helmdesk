<?php

namespace App\Actions\KnowledgeBase;

use App\Data\KnowledgeBase\FormKnowledgeSearchData;
use App\Data\KnowledgeBase\KnowledgeSearchResultData;
use App\Enums\KnowledgeIndexingStrategy;
use App\Exceptions\BusinessException;
use App\Models\KnowledgeBase;
use App\Models\Workspace;
use App\Services\KnowledgeBase\Search\ContextExpander;
use App\Services\KnowledgeBase\Search\FullTextRetriever;
use App\Services\KnowledgeBase\Search\GrepRetriever;
use App\Services\KnowledgeBase\Search\HybridFuser;
use App\Services\KnowledgeBase\Search\KnowledgeQueryEmbedder;
use App\Services\KnowledgeBase\Search\KnowledgeReranker;
use App\Services\KnowledgeBase\Search\KnowledgeSearchHit;
use App\Services\KnowledgeBase\Search\VectorRetriever;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Agent 知识库检索主入口。
 *
 * 把 grep / 全文 / 向量 / RAPTOR / rerank / context expander 这几个独立组件按 mode 编排起来。
 *
 *  - mode=grep     ：只跑 GrepRetriever，返回字面命中列表；
 *  - mode=semantic ：跑全文 + 向量 (vector) + 向量 (raptor summary)，按 RRF 融合后视配置选做 rerank + context expand；
 *  - mode=hybrid   ：semantic 与 grep 各自跑一遍，结果以两个独立数组返回，让 Agent 自行权衡。
 *
 * 真正暴露给 LLM 的字段只有三个：mode / knowledge_base_ids / query。
 * top_k、是否启用 rerank / RAPTOR / vector 等内部决策完全由本 Action 根据系统配置决定。
 */
class SearchKnowledgeBaseAction
{
    use AsAction;

    /**
     * 单次工具调用返回给 Agent 的语义命中条数上限。先固定，后续可加为工作区配置项。
     */
    private const SEMANTIC_TOP_K = 8;

    /**
     * 单次工具调用返回给 Agent 的 grep 命中条数上限。
     */
    private const GREP_TOP_K = 16;

    /**
     * RRF 融合前，各 retriever 自家保留的候选数。
     */
    private const RETRIEVER_TOP_K = 24;

    public function __construct(
        private readonly KnowledgeQueryEmbedder $queryEmbedder,
        private readonly VectorRetriever $vectorRetriever,
        private readonly FullTextRetriever $fullTextRetriever,
        private readonly GrepRetriever $grepRetriever,
        private readonly HybridFuser $hybridFuser,
        private readonly KnowledgeReranker $reranker,
        private readonly ContextExpander $contextExpander,
    ) {}

    /**
     * 对外接口。返回 Agent / 调用方使用的统一结构体。
     */
    public function handle(Workspace $workspace, FormKnowledgeSearchData $input): KnowledgeSearchResultData
    {
        $queries = $input->normalizedQueries();
        if ($queries === []) {
            throw new BusinessException(__('knowledge_search.errors.query_required'));
        }

        $knowledgeBases = $this->resolveAccessibleKnowledgeBases($workspace, $input->knowledge_base_ids);
        if ($knowledgeBases === []) {
            throw new BusinessException(__('knowledge_search.errors.knowledge_base_inaccessible'));
        }
        $knowledgeBaseIds = array_keys($knowledgeBases);

        $semanticHits = [];
        $grepHits = [];
        $debug = [
            'queries' => $queries,
            'knowledge_base_ids' => $knowledgeBaseIds,
        ];

        if ($input->mode->needsSemantic()) {
            [$semanticHits, $semanticDebug] = $this->runSemantic($workspace, $knowledgeBaseIds, $queries);
            $debug['semantic'] = $semanticDebug;
        }

        if ($input->mode->needsGrep()) {
            $grepHits = $this->grepRetriever->retrieve($knowledgeBaseIds, $queries, self::GREP_TOP_K);
            $debug['grep'] = ['hits' => count($grepHits)];
        }

        return KnowledgeSearchResultData::fromHits(
            mode: $input->mode->value,
            semanticHits: $semanticHits,
            grepMatches: $grepHits,
            debug: $debug,
        );
    }

    /**
     * 跑语义检索全流程。
     *
     * @param  list<string>  $knowledgeBaseIds
     * @param  list<string>  $queries
     * @return array{0: list<KnowledgeSearchHit>, 1: array<string, mixed>}
     */
    private function runSemantic(Workspace $workspace, array $knowledgeBaseIds, array $queries): array
    {
        $debug = [
            'vector_enabled' => false,
            'raptor_enabled' => false,
            'fulltext_enabled' => true,
            'rerank_enabled' => false,
            'rerank_applied' => false,
        ];

        $fulltextHits = $this->fullTextRetriever->retrieve(
            $knowledgeBaseIds,
            $queries,
            self::RETRIEVER_TOP_K,
        );

        $vectorEnabled = $workspace->knowledge_vector_index_enabled;
        $raptorEnabled = $workspace->knowledge_raptor_index_enabled;

        $vectorHits = [];
        $raptorHits = [];
        if ($vectorEnabled || $raptorEnabled) {
            $dimension = 0;
            $embeddings = [];
            try {
                [$dimension, $embeddings] = $this->queryEmbedder->embed($workspace, $queries);
            } catch (Throwable $exception) {
                // 单点 embedding 失败时只回退到全文检索；debug 给稳定错误码，详细异常仅落服务端日志。
                $debug['embedding_error'] = 'embedding_unavailable';
                Log::warning('Knowledge search embed failed; full-text only.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
            $workspace->loadMissing('knowledgeEmbeddingModel');
            $embeddingModelId = $workspace->knowledgeEmbeddingModel?->id;
            if ($dimension > 0 && $embeddings !== []) {
                if ($vectorEnabled) {
                    $vectorHits = $this->vectorRetriever->retrieve(
                        knowledgeBaseIds: $knowledgeBaseIds,
                        dimension: $dimension,
                        queryEmbeddings: $embeddings,
                        strategies: [KnowledgeIndexingStrategy::Text],
                        topK: self::RETRIEVER_TOP_K,
                        embeddingModelId: $embeddingModelId,
                        sourceLabel: 'vector',
                    );
                    $debug['vector_enabled'] = true;
                    $debug['vector_hits'] = count($vectorHits);
                }
                if ($raptorEnabled) {
                    $raptorHits = $this->vectorRetriever->retrieve(
                        knowledgeBaseIds: $knowledgeBaseIds,
                        dimension: $dimension,
                        queryEmbeddings: $embeddings,
                        strategies: [KnowledgeIndexingStrategy::Raptor],
                        topK: self::RETRIEVER_TOP_K,
                        embeddingModelId: $embeddingModelId,
                        sourceLabel: 'raptor',
                    );
                    $debug['raptor_enabled'] = true;
                    $debug['raptor_hits'] = count($raptorHits);
                }
            }
        }
        $debug['fulltext_hits'] = count($fulltextHits);

        $fused = $this->hybridFuser->fuse(
            array_merge($fulltextHits, $vectorHits, $raptorHits),
            self::RETRIEVER_TOP_K,
        );

        $rerankModel = $workspace->knowledgeRerankModel;
        if ($rerankModel !== null && $rerankModel->provider !== null) {
            $debug['rerank_enabled'] = true;
            $rerankResult = $this->reranker->rerank($workspace, $queries[0], $fused, self::SEMANTIC_TOP_K);
            $debug['rerank_applied'] = $rerankResult->applied;
            if ($rerankResult->errorCode !== null) {
                $debug['rerank_error'] = $rerankResult->errorCode;
            }
            $fused = $rerankResult->hits;
        }

        if (count($fused) > self::SEMANTIC_TOP_K) {
            $fused = array_slice($fused, 0, self::SEMANTIC_TOP_K);
        }

        $expanded = $this->contextExpander->expand($fused);

        return [$expanded, $debug];
    }

    /**
     * 把入参的知识库 ID 收敛到当前系统内可访问的；空列表表示全部知识库。
     *
     * @param  list<string>  $candidateIds
     * @return array<string, KnowledgeBase>
     */
    private function resolveAccessibleKnowledgeBases(Workspace $workspace, array $candidateIds): array
    {
        $cleanIds = [];
        foreach ($candidateIds as $id) {
            $trimmed = trim((string) $id);
            if ($trimmed === '') {
                continue;
            }
            if (! in_array($trimmed, $cleanIds, true)) {
                $cleanIds[] = $trimmed;
            }
        }
        $query = KnowledgeBase::query();
        if ($cleanIds !== []) {
            $query->whereIn('id', $cleanIds);
        }

        $records = $query->get()->keyBy('id');

        $resolved = [];
        foreach ($records as $kb) {
            $resolved[$kb->id] = $kb;
        }

        return $resolved;
    }
}
