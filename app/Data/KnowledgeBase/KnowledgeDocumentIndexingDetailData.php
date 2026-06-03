<?php

namespace App\Data\KnowledgeBase;

use App\Enums\KnowledgeIndexingStrategy;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use Spatie\LaravelData\Data;

/**
 * 文档解析与各索引策略的细化状态，用于列表行右侧多个状态徽章及"重新索引"操作。
 *
 * 由 ListKnowledgeDocumentItemData 在转换列表项时构造。
 */
class KnowledgeDocumentIndexingDetailData extends Data
{
    /**
     * @param  array<int, KnowledgeDocumentStageStatusData>  $stages  parse + 已启用策略的阶段状态集合
     */
    public function __construct(
        public string $overall_status,
        public string $overall_status_label,
        public array $stages,
    ) {}

    /**
     * 从知识库和文档模型构造每个展示阶段的状态。
     *
     * @param  list<KnowledgeIndexingStrategy>  $enabledStrategies
     */
    public static function fromModels(KnowledgeBase $knowledgeBase, KnowledgeDocument $document, array $enabledStrategies): self
    {
        $stages = [];

        $stages[] = new KnowledgeDocumentStageStatusData(
            stage: 'parse',
            stage_label: __('knowledge_base.documents.stages.parse'),
            status: $document->parse_status->value,
            status_label: $document->parse_status->label(),
            error_message: filled($document->parse_error) ? (string) $document->parse_error : null,
            finished_at: $document->parsed_at?->toIso8601String(),
            enabled: true,
        );

        foreach (KnowledgeIndexingStrategy::togglableCases() as $strategy) {
            if (! in_array($strategy, $enabledStrategies, true)) {
                continue;
            }

            $status = $document->indexingStatusFor($strategy);
            [$error, $finishedAt] = match ($strategy) {
                KnowledgeIndexingStrategy::Vector => [$document->vector_error, $document->vector_indexed_at],
                KnowledgeIndexingStrategy::Raptor => [$document->raptor_error, $document->raptor_indexed_at],
                KnowledgeIndexingStrategy::Text => [null, null],
            };

            $stages[] = new KnowledgeDocumentStageStatusData(
                stage: $strategy->value,
                stage_label: $strategy->label(),
                status: $status->value,
                status_label: $status->label(),
                error_message: filled($error) ? (string) $error : null,
                finished_at: $finishedAt?->toIso8601String(),
                enabled: true,
            );
        }

        $overall = $document->deriveOverallStatus($knowledgeBase);

        return new self(
            overall_status: $overall->value,
            overall_status_label: $overall->label(),
            stages: $stages,
        );
    }
}
