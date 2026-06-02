<?php

namespace App\Actions\KnowledgeBase\Indexing;

use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeQaEntryStatus;
use App\Jobs\KnowledgeDocument\IndexRaptorKnowledgeDocumentJob;
use App\Jobs\KnowledgeDocument\IndexVectorKnowledgeDocumentJob;
use App\Jobs\KnowledgeQa\IndexVectorKnowledgeQaEntryJob;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeQaEntry;
use App\Models\SystemContext;
use App\Services\KnowledgeBase\KnowledgeNodeRepository;
use App\Services\KnowledgeBase\KnowledgeVectorTableManager;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 重建知识库文档和问答索引。
 */
class RebuildSystemKnowledgeIndexAction
{
    use AsAction;

    /**
     * 注入知识节点仓储和向量表管理器。
     */
    public function __construct(
        private readonly KnowledgeNodeRepository $nodes,
        private readonly KnowledgeVectorTableManager $vectorTables,
    ) {}

    /**
     * 执行文档和 QA 的索引重建流程。
     *
     * @param  list<string>  $documentStrategyValues
     */
    public function handle(
        array $documentStrategyValues,
        bool $rebuildQaVectorIndex,
        bool $resetVectorTables = false,
    ): void {
        $systemContext = SystemContext::current();
        $strategies = $this->resolveStrategies($documentStrategyValues);

        if ($resetVectorTables) {
            $this->vectorTables->resetAllTables();
            $this->bulkPurgeSystemNodes($strategies);
        }

        foreach ($strategies as $strategy) {
            $this->rebuildDocumentStrategy($systemContext, $strategy, $resetVectorTables);
        }

        if ($rebuildQaVectorIndex) {
            $this->rebuildQaVectorIndex($systemContext, $resetVectorTables);
        }
    }

    /**
     * 把队列载荷里的策略 value 转成枚举。
     *
     * @param  list<string>  $strategyValues
     * @return list<KnowledgeIndexingStrategy>
     */
    private function resolveStrategies(array $strategyValues): array
    {
        $strategies = [];
        foreach ($strategyValues as $strategyValue) {
            $strategy = KnowledgeIndexingStrategy::tryFrom((string) $strategyValue);
            if ($strategy !== null) {
                $strategies[] = $strategy;
            }
        }

        return $strategies;
    }

    /**
     * 维度变更时一次性清掉需要重建的策略节点。
     *
     * @param  list<KnowledgeIndexingStrategy>  $strategies
     */
    private function bulkPurgeSystemNodes(array $strategies): void
    {
        $strategyValues = array_values(array_map(
            static fn (KnowledgeIndexingStrategy $strategy): string => $strategy->value,
            $strategies,
        ));

        $query = KnowledgeNode::query();
        if ($strategyValues !== []) {
            $query->whereIn('strategy', $strategyValues);
        }
        $query->delete();

        // canonical text 节点仍保留，但旧维度向量已随 vec0 表消失，需清掉向量元信息。
        KnowledgeNode::query()
            ->where('strategy', KnowledgeIndexingStrategy::Text)
            ->where('embedding_dim', '>', 0)
            ->update([
                'embedding_dim' => 0,
                'embedding_model_id' => null,
            ]);
    }

    /**
     * 遍历文档，按策略清旧索引、标记状态并派发逐条索引任务。
     */
    private function rebuildDocumentStrategy(SystemContext $systemContext, KnowledgeIndexingStrategy $strategy, bool $resetVectorTables): void
    {
        KnowledgeDocument::query()
            ->with('knowledgeBase')
            ->each(function (KnowledgeDocument $document) use ($systemContext, $strategy, $resetVectorTables): void {
                $knowledgeBase = $document->knowledgeBase;
                if ($knowledgeBase === null) {
                    return;
                }
                $knowledgeBase->setRelation('systemContext', $systemContext);

                if (! $resetVectorTables) {
                    $this->nodes->purgeStrategyForDocument($document, $strategy);
                }

                $status = $systemContext->hasKnowledgeIndexingStrategy($strategy)
                    ? KnowledgeDocumentIndexingStatus::Pending
                    : KnowledgeDocumentIndexingStatus::Idle;

                $document->updateStageStatus($strategy, $status, knowledgeBase: $knowledgeBase);

                if ($document->parse_status === KnowledgeDocumentParseStatus::Succeeded
                    && $systemContext->hasKnowledgeIndexingStrategy($strategy)) {
                    match ($strategy) {
                        KnowledgeIndexingStrategy::Vector => IndexVectorKnowledgeDocumentJob::dispatch((string) $document->id),
                        KnowledgeIndexingStrategy::Raptor => IndexRaptorKnowledgeDocumentJob::dispatch((string) $document->id),
                    };
                }
            });
    }

    /**
     * 遍历问答条目，清向量索引并按需重新派发。
     */
    private function rebuildQaVectorIndex(SystemContext $systemContext, bool $resetVectorTables): void
    {
        $enabled = $systemContext->hasKnowledgeIndexingStrategy(KnowledgeIndexingStrategy::Vector);

        KnowledgeQaEntry::query()
            ->with('knowledgeBase')
            ->each(function (KnowledgeQaEntry $entry) use ($systemContext, $enabled, $resetVectorTables): void {
                $knowledgeBase = $entry->knowledgeBase;
                if ($knowledgeBase === null) {
                    return;
                }
                $knowledgeBase->setRelation('systemContext', $systemContext);

                if (! $resetVectorTables) {
                    $this->nodes->purgeStrategyForQaEntry($entry, KnowledgeIndexingStrategy::Vector);
                }

                $entry->forceFill([
                    'vector_status' => $enabled
                        ? KnowledgeDocumentIndexingStatus::Pending
                        : KnowledgeDocumentIndexingStatus::Idle,
                    'vector_error' => null,
                    'vector_indexed_at' => null,
                    'status' => $enabled
                        ? KnowledgeQaEntryStatus::Pending
                        : KnowledgeQaEntryStatus::Indexed,
                    'error_message' => null,
                ])->save();

                if ($enabled) {
                    IndexVectorKnowledgeQaEntryJob::dispatch((string) $entry->id);
                }
            });
    }
}
