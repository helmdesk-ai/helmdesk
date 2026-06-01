<?php

namespace App\Actions\KnowledgeBase\Indexing;

use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeBase\KnowledgeDocumentSourceMaterializer;
use App\Services\KnowledgeBase\Parsing\DocumentParserManager;
use App\Services\KnowledgeBase\Parsing\MarkdownChunker;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 文档解析阶段，读取原始文件并写入归一化 Markdown、大纲和索引初始状态。
 */
class ParseKnowledgeDocumentAction
{
    use AsAction;

    /**
     * 注入解析器、分块器、文件物化器和 canonical 节点写入器。
     *
     * canonical 写入会同时落 strategy=text 节点 + knowledge_fts 行；
     * 后续 Vector / RAPTOR Job 都基于这一批 canonical 节点继续工作。
     */
    public function __construct(
        private readonly DocumentParserManager $parsers,
        private readonly MarkdownChunker $chunker,
        private readonly KnowledgeDocumentSourceMaterializer $materializer,
        private readonly WriteCanonicalChunksAction $canonicalWriter,
    ) {}

    /**
     * 执行一次解析并写入全文索引。
     */
    public function handle(KnowledgeDocument $document): bool
    {
        $document->refresh();
        $knowledgeBase = $document->knowledgeBase;
        if ($knowledgeBase === null) {
            return false;
        }

        $document->update([
            'parse_status' => KnowledgeDocumentParseStatus::Processing,
            'parse_error' => null,
        ]);

        $tempPath = null;
        try {
            $source = $this->materializer->materialize($document);
            $tempPath = $source['path'];

            $parsed = $this->parsers->parse(
                absoluteFilePath: $source['path'],
                mimeType: $source['mime_type'],
                extension: $source['extension'],
            );

            $markdown = trim($parsed->markdown);
            if ($markdown === '') {
                throw new \RuntimeException(__('knowledge_base.documents.errors.no_segments'));
            }

            $outline = $this->chunker->outline($markdown);

            $metadata = $parsed->metadata;
            $metadata['outline'] = $outline;
            $metadata['outline_count'] = count($outline);

            $document->update([
                'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
                'parse_error' => null,
                'parsed_at' => now(),
                'parsed_content_format' => $parsed->contentFormat,
                'parsed_content' => $markdown,
                'parse_metadata' => $metadata,
                'vector_status' => $knowledgeBase->hasIndexingStrategy(KnowledgeIndexingStrategy::Vector)
                    ? KnowledgeDocumentIndexingStatus::Pending
                    : KnowledgeDocumentIndexingStatus::Idle,
                'raptor_status' => $knowledgeBase->hasIndexingStrategy(KnowledgeIndexingStrategy::Raptor)
                    ? KnowledgeDocumentIndexingStatus::Pending
                    : KnowledgeDocumentIndexingStatus::Idle,
            ]);

            $this->canonicalWriter->forDocument($document);
            $document->refreshOverallStatus($knowledgeBase);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Knowledge document parse failed.', [
                'document_id' => $document->id,
                'message' => $exception->getMessage(),
            ]);

            $document->update([
                'parse_status' => KnowledgeDocumentParseStatus::Failed,
                'parse_error' => $exception->getMessage(),
            ]);
            $document->refreshOverallStatus($knowledgeBase);

            throw $exception;
        } finally {
            if ($tempPath !== null) {
                $this->materializer->cleanup($tempPath);
            }
        }
    }
}
