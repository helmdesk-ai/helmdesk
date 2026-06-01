<?php

namespace App\Jobs\KnowledgeDocument;

use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeDocumentVectorAction;
use App\Models\KnowledgeDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 向量索引队列任务：批量分块 + 嵌入 + 写 vec0。
 */
class IndexVectorKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    /**
     * 创建向量索引任务。
     */
    public function __construct(public readonly string $documentId) {}

    /**
     * 执行文档向量索引。
     */
    public function handle(IndexKnowledgeDocumentVectorAction $action): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);
        if ($document === null) {
            Log::info('IndexVectorKnowledgeDocumentJob: document missing, skipped.', [
                'document_id' => $this->documentId,
            ]);

            return;
        }

        $action->handle($document);
    }
}
