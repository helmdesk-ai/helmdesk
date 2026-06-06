<?php

namespace App\Actions\KnowledgeBase\Indexing;

use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use App\Exceptions\BusinessException;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use App\Models\SystemContext;
use App\Services\KnowledgeBase\KnowledgeEmbeddingService;
use App\Services\KnowledgeBase\KnowledgeNodeRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 文档向量索引阶段。
 *
 *  - 读取 strategy=text 的 canonical 节点；
 *  - 经 KnowledgeEmbeddingService 批量生成向量；
 *  - 通过 KnowledgeNodeRepository::attachVectors() 把向量挂回同一批 node_id，
 *    让 FT / Vector 在 RRF 合并时按 node_id 去重。
 */
class IndexKnowledgeDocumentVectorAction
{
    use AsAction;

    public function __construct(
        private readonly KnowledgeEmbeddingService $embedder,
        private readonly KnowledgeNodeRepository $nodes,
    ) {}

    /**
     * 为指定文档构建向量索引。
     */
    public function handle(KnowledgeDocument $document): void
    {
        $document->refresh();
        $kb = $document->knowledgeBase;
        if ($kb === null) {
            throw new BusinessException(__('knowledge_base.documents.errors.parsed_content_missing'));
        }
        $systemContext = SystemContext::current();
        if (! $kb->hasIndexingStrategy(KnowledgeIndexingStrategy::Vector)) {
            $document->updateStageStatus(KnowledgeIndexingStrategy::Vector, KnowledgeDocumentIndexingStatus::Idle, knowledgeBase: $kb);

            return;
        }
        if ($document->parse_status !== KnowledgeDocumentParseStatus::Succeeded || ! filled($document->parsed_content)) {
            throw new BusinessException(__('knowledge_base.documents.errors.parsed_content_missing'));
        }

        $embeddingModel = $systemContext->knowledgeEmbeddingModel;
        if ($embeddingModel === null || $embeddingModel->provider === null) {
            throw new BusinessException(__('knowledge_base.messages.invalid_embedding_model'));
        }

        $document->updateStageStatus(KnowledgeIndexingStrategy::Vector, KnowledgeDocumentIndexingStatus::Processing, knowledgeBase: $kb);

        try {
            $canonicalNodes = $this->loadCanonicalNodes($document);
            if ($canonicalNodes->isEmpty()) {
                throw new BusinessException(__('knowledge_base.documents.errors.no_segments'));
            }

            $contents = $canonicalNodes->map(static fn (KnowledgeNode $node): string => (string) $node->content)->all();
            [$dimension, $vectors] = $this->embedder->embedTexts($embeddingModel, $contents);
            if ($dimension <= 0 || count($vectors) !== $canonicalNodes->count()) {
                throw new BusinessException(__('knowledge_base.documents.errors.embedding_failed'));
            }

            $embeddings = [];
            foreach ($canonicalNodes as $idx => $node) {
                $embeddings[(string) $node->id] = $vectors[$idx];
            }

            $this->nodes->attachVectors($kb, $dimension, $embeddings);

            $document->updateStageStatus(KnowledgeIndexingStrategy::Vector, KnowledgeDocumentIndexingStatus::Succeeded, knowledgeBase: $kb);
        } catch (Throwable $exception) {
            Log::warning('Vector indexing failed.', [
                'document_id' => $document->id,
                'message' => $exception->getMessage(),
            ]);
            $document->updateStageStatus(
                KnowledgeIndexingStrategy::Vector,
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
}
