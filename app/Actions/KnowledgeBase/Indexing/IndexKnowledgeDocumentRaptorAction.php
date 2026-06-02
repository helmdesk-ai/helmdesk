<?php

namespace App\Actions\KnowledgeBase\Indexing;

use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use App\Models\Workspace;
use App\Services\KnowledgeBase\GoKnowledgeBridge;
use App\Services\KnowledgeBase\KnowledgeEmbeddingService;
use App\Services\KnowledgeBase\KnowledgeNodeRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * RAPTOR 索引：基于 canonical 文本节点自底向上构建多层摘要树。
 *
 *  1. 从 strategy=text 的 canonical 节点开始；
 *  2. 逐层做 sequential cluster + LLM 摘要：
 *     - 摘要节点为 strategy=raptor / kind=summary / level>=1，且自带嵌入向量参与向量召回；
 *     - 每个 cluster 的子节点 ID 通过 setParentForNodes 写入 parent_id，只串联摘要内部，
 *       canonical 叶子的 parent_id 保持 null，避免污染全文 / 向量检索；
 *  3. 单点收敛或达到 MAX_LEVELS 即停止；顶层摘要节点的 parent_id 保持 null，作为树根。
 *
 * 关于 cluster：当前用 array_chunk 做"顺序聚类"，对结构化文档已足够；
 * 后续如需严格语义聚类（UMAP + GMM），替换 buildClusters() 即可。
 *
 * 本 Action 不会为 canonical text 叶子额外挂向量；那是 IndexKnowledgeDocumentVectorAction 的职责。
 * 这保证 Vector / Raptor 两个 toggle 在工作区配置上完全正交。
 */
class IndexKnowledgeDocumentRaptorAction
{
    use AsAction;

    /**
     * 每个簇内最多包含的子节点数；簇越大摘要越粗，召回更宽。
     */
    private const CLUSTER_BRANCHING = 4;

    /**
     * 摘要树最大层数，防止递归过深。
     */
    private const MAX_LEVELS = 3;

    public function __construct(
        private readonly GoKnowledgeBridge $bridge,
        private readonly KnowledgeEmbeddingService $embedder,
        private readonly KnowledgeNodeRepository $nodes,
    ) {}

    /**
     * 为指定文档生成 RAPTOR 摘要树。
     */
    public function handle(KnowledgeDocument $document): void
    {
        $document->refresh();
        $kb = $document->knowledgeBase;
        if ($kb === null) {
            throw new BusinessException(__('knowledge_base.documents.errors.parsed_content_missing'));
        }
        $workspace = Workspace::current();
        if (! $kb->hasIndexingStrategy(KnowledgeIndexingStrategy::Raptor)) {
            $document->updateStageStatus(KnowledgeIndexingStrategy::Raptor, KnowledgeDocumentIndexingStatus::Idle, knowledgeBase: $kb);

            return;
        }

        $summaryModel = $workspace->knowledgeSummaryModel;
        if ($summaryModel === null || $summaryModel->provider === null) {
            throw new BusinessException(__('knowledge_base.messages.invalid_summary_model'));
        }
        $embeddingModel = $workspace->knowledgeEmbeddingModel;
        if ($embeddingModel === null || $embeddingModel->provider === null) {
            throw new BusinessException(__('knowledge_base.messages.invalid_embedding_model'));
        }
        if ($document->parse_status !== KnowledgeDocumentParseStatus::Succeeded || ! filled($document->parsed_content)) {
            throw new BusinessException(__('knowledge_base.documents.errors.parsed_content_missing'));
        }

        $document->updateStageStatus(KnowledgeIndexingStrategy::Raptor, KnowledgeDocumentIndexingStatus::Processing, knowledgeBase: $kb);

        try {
            $this->nodes->purgeStrategyForDocument($document, KnowledgeIndexingStrategy::Raptor);

            $canonicalNodes = $this->loadCanonicalNodes($document);
            if ($canonicalNodes->isEmpty()) {
                throw new BusinessException(__('knowledge_base.documents.errors.no_segments'));
            }

            // 自底向上做 cluster + 摘要 + 嵌入 + 摘要树 parent 串接。
            // 嵌入维度由首次摘要批次返回值决定，避免对 canonical 叶子做多余嵌入。
            $currentLayer = [];
            foreach ($canonicalNodes as $node) {
                $currentLayer[] = ['id' => (string) $node->id, 'content' => (string) $node->content];
            }

            $dimension = 0;
            $summaryCredentials = $this->embedder->credentialsFor($summaryModel->provider);
            for ($level = 1; $level <= self::MAX_LEVELS && count($currentLayer) > 1; $level++) {
                [$currentLayer, $dimension] = $this->buildNextLayer(
                    knowledgeBase: $kb,
                    document: $document,
                    level: $level,
                    currentLayer: $currentLayer,
                    dimension: $dimension,
                    embeddingModel: $embeddingModel,
                    summaryModel: $summaryModel,
                    summaryCredentials: $summaryCredentials,
                );
                if (count($currentLayer) <= 1) {
                    break;
                }
            }

            $document->updateStageStatus(KnowledgeIndexingStrategy::Raptor, KnowledgeDocumentIndexingStatus::Succeeded, knowledgeBase: $kb);
        } catch (Throwable $exception) {
            Log::warning('RAPTOR indexing failed.', [
                'document_id' => $document->id,
                'message' => $exception->getMessage(),
            ]);
            $document->updateStageStatus(
                KnowledgeIndexingStrategy::Raptor,
                KnowledgeDocumentIndexingStatus::Failed,
                error: $exception->getMessage(),
                knowledgeBase: $kb,
            );
            throw $exception;
        }
    }

    /**
     * @return Collection<int, KnowledgeNode>
     */
    private function loadCanonicalNodes(KnowledgeDocument $document): Collection
    {
        return KnowledgeNode::query()
            ->where('document_id', $document->id)
            ->where('strategy', KnowledgeIndexingStrategy::Text)
            ->where('kind', KnowledgeNodeKind::Segment)
            ->orderByRaw('COALESCE(byte_start, 0) ASC')
            ->orderBy('id')
            ->get();
    }

    /**
     * 把当前层做一次聚类 + 摘要 + 嵌入 + 写库，返回 [新一层节点列表, 嵌入维度]。
     *
     * @param  list<array{id: string, content: string}>  $currentLayer
     * @param  array<string, mixed>  $summaryCredentials
     * @return array{0: list<array{id: string, content: string}>, 1: int}
     */
    private function buildNextLayer(
        KnowledgeBase $knowledgeBase,
        KnowledgeDocument $document,
        int $level,
        array $currentLayer,
        int $dimension,
        AiModel $embeddingModel,
        AiModel $summaryModel,
        array $summaryCredentials,
    ): array {
        $clusters = $this->buildClusters($currentLayer, self::CLUSTER_BRANCHING);
        if ($clusters === []) {
            return [[], $dimension];
        }

        $batches = array_map(
            static fn (array $cluster) => array_values(array_map(
                static fn (array $item) => (string) $item['content'],
                $cluster,
            )),
            $clusters,
        );

        $summaryResponse = $this->bridge->summarizeBatches($summaryModel->provider, $summaryModel, $summaryCredentials, $batches);
        $summaries = $summaryResponse['summaries'] ?? [];
        if (count($summaries) !== count($clusters)) {
            throw new BusinessException(__('knowledge_base.documents.errors.summary_failed'));
        }

        $validClusters = [];
        $validSummaries = [];
        foreach ($clusters as $index => $cluster) {
            $summary = trim((string) ($summaries[$index] ?? ''));
            if ($summary === '') {
                continue;
            }
            $validClusters[] = $cluster;
            $validSummaries[] = $summary;
        }
        if ($validSummaries === []) {
            return [[], $dimension];
        }

        [$summaryDimension, $summaryVectors] = $this->embedder->embedTexts($embeddingModel, $validSummaries);
        if ($summaryDimension <= 0) {
            throw new BusinessException(__('knowledge_base.documents.errors.embedding_failed'));
        }
        if ($dimension > 0 && $summaryDimension !== $dimension) {
            throw new BusinessException(__('knowledge_base.documents.errors.embedding_failed'));
        }
        $dimension = $summaryDimension;

        $nextLayer = [];
        foreach ($validClusters as $index => $cluster) {
            $childrenIds = array_values(array_map(static fn (array $item) => (string) $item['id'], $cluster));
            $summaryContent = $validSummaries[$index];

            $nodeId = $this->nodes->writeSummaryNode(
                knowledgeBase: $knowledgeBase,
                document: $document,
                level: $level,
                parentId: null,
                content: $summaryContent,
                embeddingDimension: $dimension,
                embedding: $summaryVectors[$index],
                childrenIds: $childrenIds,
                metadata: ['level' => $level],
            );

            // 把上一层摘要节点的 parent_id 串到刚生成的摘要节点；
            // setParentForNodes 内部只匹配 strategy=raptor，canonical 叶子的 parent_id 保持 null。
            $this->nodes->setParentForNodes($childrenIds, $nodeId);

            $nextLayer[] = ['id' => $nodeId, 'content' => $summaryContent];
        }

        return [$nextLayer, $dimension];
    }

    /**
     * 按 branching 顺序聚类。后续若引入语义聚类，只需替换这里的实现。
     *
     * @param  list<array{id: string, content: string}>  $items
     * @return list<list<array{id: string, content: string}>>
     */
    private function buildClusters(array $items, int $branching): array
    {
        $size = max(2, $branching);

        return array_chunk($items, $size);
    }
}
