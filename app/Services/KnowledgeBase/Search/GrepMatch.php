<?php

namespace App\Services\KnowledgeBase\Search;

/**
 * 单条 grep 命中。
 *
 * 与 KnowledgeSearchHit 平行存在；grep 命中是"位置 + 行 + 周边"的结构，
 * 难以与向量 / 全文按 node_id 做 RRF，所以保留独立形态。
 */
final class GrepMatch
{
    public function __construct(
        public readonly string $knowledgeBaseId,
        public readonly ?string $documentId,
        public readonly ?string $documentTitle,
        public readonly ?string $qaEntryId,
        public readonly ?string $qaQuestionId,
        public readonly ?string $qaAnswerId,
        public readonly string $field,
        public readonly string $query,
        public readonly int $line,
        public readonly int $column,
        public readonly int $byteStart,
        public readonly int $byteEnd,
        public readonly string $match,
        public readonly string $contextBefore,
        public readonly string $contextAfter,
        public readonly ?string $headingPath = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'knowledge_base_id' => $this->knowledgeBaseId,
            'document_id' => $this->documentId,
            'document_title' => $this->documentTitle,
            'qa_entry_id' => $this->qaEntryId,
            'qa_question_id' => $this->qaQuestionId,
            'qa_answer_id' => $this->qaAnswerId,
            'field' => $this->field,
            'query' => $this->query,
            'line' => $this->line,
            'column' => $this->column,
            'byte_start' => $this->byteStart,
            'byte_end' => $this->byteEnd,
            'match' => $this->match,
            'context_before' => $this->contextBefore,
            'context_after' => $this->contextAfter,
            'heading_path' => $this->headingPath,
        ];
    }
}
