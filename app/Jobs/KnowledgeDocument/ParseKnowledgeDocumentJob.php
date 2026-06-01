<?php

namespace App\Jobs\KnowledgeDocument;

use App\Actions\KnowledgeBase\Indexing\DispatchKnowledgeDocumentPipelineAction;
use App\Actions\KnowledgeBase\Indexing\ParseKnowledgeDocumentAction;
use App\Models\KnowledgeDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 文档解析队列任务：调用 PHP 端 DocumentParserManager 完成解析，成功后派发各索引 Job。
 *
 * 失败时不在这里重试 —— 让 Laravel 的队列重试机制按 tries 处理；超过 tries 后保留 parse_status=Failed，
 * 由用户通过"重新索引"按钮显式重试。
 */
class ParseKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    /**
     * 大文档解析慢；给到 5 分钟队列超时与 3 次重试。
     */
    public int $timeout = 300;

    public int $tries = 3;

    /**
     * 创建文档解析任务。
     */
    public function __construct(public readonly string $documentId) {}

    /**
     * 执行文档解析并派发后续索引任务。
     */
    public function handle(
        ParseKnowledgeDocumentAction $parseAction,
        DispatchKnowledgeDocumentPipelineAction $dispatcher,
    ): void {
        $document = KnowledgeDocument::query()->find($this->documentId);
        if ($document === null) {
            Log::info('ParseKnowledgeDocumentJob: document missing, skipped.', [
                'document_id' => $this->documentId,
            ]);

            return;
        }

        $parsed = $parseAction->handle($document);
        if ($parsed) {
            $dispatcher->dispatchIndexingForParsedDocument($document->fresh() ?? $document);
        }
    }
}
