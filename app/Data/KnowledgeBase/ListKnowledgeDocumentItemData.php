<?php

namespace App\Data\KnowledgeBase;

use App\Enums\KnowledgeDocumentSourceType;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use Spatie\LaravelData\Data;

/**
 * 知识库文档列表项 Data，用于 resources/js/pages/knowledgeBase/List.vue 主区文档表格的每一行。
 * source_type 决定列表行的图标和操作（manual 类型显示可编辑入口）。
 * indexing 字段下发解析与语义增强索引的细化状态，用于右侧多 badge 展示与"重新索引"操作。
 */
class ListKnowledgeDocumentItemData extends Data
{
    public function __construct(
        public string $id,
        public string $knowledge_base_id,
        public string $group_id,
        public string $original_filename,
        public string $mime_type,
        public int $byte_size,
        public ?string $extension,
        public KnowledgeDocumentSourceType $source_type,
        public KnowledgeDocumentStatus $status,
        public string $status_label,
        public ?string $error_message,
        public KnowledgeDocumentIndexingDetailData $indexing,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}

    /**
     * 从 Eloquent 模型构造列表项。
     * 调用方通过 fromCollection 批量构造时，knowledgeBase 上的 enabledIndexingStrategies 仅需解析一次。
     *
     * 列表行展示的综合状态以 KnowledgeDocument.status 列为准（由流水线写入），
     * 这样老数据 / 测试夹具直接 set status 也能被正确展示，不依赖派生计算。
     */
    public static function fromModel(KnowledgeDocument $document, KnowledgeBase $knowledgeBase): self
    {
        $strategies = $knowledgeBase->enabledIndexingStrategies();

        return new self(
            id: (string) $document->id,
            knowledge_base_id: (string) $document->knowledge_base_id,
            group_id: (string) $document->group_id,
            original_filename: $document->original_filename,
            mime_type: $document->mime_type,
            byte_size: (int) $document->byte_size,
            extension: filled($document->extension) ? (string) $document->extension : null,
            source_type: $document->source_type,
            status: $document->status,
            status_label: $document->status->label(),
            error_message: filled($document->error_message) ? (string) $document->error_message : null,
            indexing: KnowledgeDocumentIndexingDetailData::fromModels($knowledgeBase, $document, $strategies),
            created_at: $document->created_at?->toIso8601String(),
            updated_at: $document->updated_at?->toIso8601String(),
        );
    }

    /**
     * 批量构造列表项，共享同一份已启用策略解析结果。
     *
     * @param  iterable<KnowledgeDocument>  $documents
     * @return list<self>
     */
    public static function fromCollection(iterable $documents, KnowledgeBase $knowledgeBase): array
    {
        $items = [];
        foreach ($documents as $document) {
            $items[] = self::fromModel($document, $knowledgeBase);
        }

        return $items;
    }
}
