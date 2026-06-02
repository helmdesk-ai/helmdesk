<?php

namespace App\Actions\KnowledgeBase\Indexing;

use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Exceptions\BusinessException;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeQaEntry;
use App\Models\Workspace;
use App\Services\KnowledgeBase\KnowledgeFullTextRepository;
use App\Services\KnowledgeBase\KnowledgeNodeRepository;
use App\Services\KnowledgeBase\Parsing\MarkdownChunkPlanner;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 把解析后的文档 / 问答条目写成 canonical 文本分段（strategy=text）。
 *
 * 这一步是检索体系的统一数据源：
 *  - 全文检索（FTS5）以这一批 canonical 节点作为唯一索引来源；
 *  - 向量检索通过 attachVectors() 在同一批节点上追加 vec0 行；
 *  - RAPTOR 把 canonical 节点视为 level=0 叶子，仅再叠加 strategy=raptor 摘要节点；
 *  - grep 直接扫 KnowledgeDocument::parsed_content / QA 字段，不读节点。
 *
 * 索引文档的固定步骤：
 *   1) ParseAction → 解析出 parsed_content；
 *   2) WriteCanonicalChunksAction → 生成 canonical 节点 + FTS 行；
 *   3) 向量 / RAPTOR Job 在 canonical 集合之上构建。
 */
class WriteCanonicalChunksAction
{
    use AsAction;

    public function __construct(
        private readonly MarkdownChunkPlanner $planner,
        private readonly KnowledgeNodeRepository $nodes,
        private readonly KnowledgeFullTextRepository $fts,
    ) {}

    /**
     * 为指定文档写 canonical 文本节点 + FTS 行。返回已经按 sort_order(byte_start) 排序的节点集合。
     *
     * @return Collection<int, KnowledgeNode>
     */
    public function forDocument(KnowledgeDocument $document): Collection
    {
        $document->refresh();
        $knowledgeBase = $document->knowledgeBase;
        if ($knowledgeBase === null) {
            throw new BusinessException(__('knowledge_base.documents.errors.parsed_content_missing'));
        }
        $workspace = Workspace::current();

        if ($document->parse_status !== KnowledgeDocumentParseStatus::Succeeded || ! filled($document->parsed_content)) {
            throw new BusinessException(__('knowledge_base.documents.errors.parsed_content_missing'));
        }

        $segments = $this->planner->plan($workspace, (string) $document->parsed_content);
        if ($segments === []) {
            throw new BusinessException(__('knowledge_base.documents.errors.no_segments'));
        }

        $payload = [];
        foreach ($segments as $segment) {
            $payload[] = [
                'content' => (string) ($segment['content'] ?? ''),
                'content_format' => 'markdown',
                'heading_path' => $this->planner->joinHeadingPath($segment['heading_path'] ?? null),
                'byte_start' => isset($segment['byte_start']) ? (int) $segment['byte_start'] : null,
                'byte_end' => isset($segment['byte_end']) ? (int) $segment['byte_end'] : null,
                'token_count' => isset($segment['token_count']) ? (int) $segment['token_count'] : null,
                'metadata' => null,
            ];
        }

        $this->purgeForDocument($knowledgeBase, $document);
        $ids = $this->nodes->writeCanonicalSegments($knowledgeBase, $document, $payload);
        $nodes = $this->reloadNodes($ids);

        $outline = is_array($document->parse_metadata['outline'] ?? null)
            ? $document->parse_metadata['outline']
            : null;
        $this->fts->indexDocument($knowledgeBase, $document, $nodes, $outline);

        return $nodes;
    }

    /**
     * 为指定问答条目写 canonical 节点（主问题 / 相似问 / 已启用答案）+ FTS 行。
     *
     * @return Collection<int, KnowledgeNode>
     */
    public function forQaEntry(KnowledgeQaEntry $entry): Collection
    {
        $entry->refresh();
        $knowledgeBase = $entry->knowledgeBase;
        if ($knowledgeBase === null) {
            throw new BusinessException(__('knowledge_base.qa.errors.not_qa_knowledge_base'));
        }

        $items = $this->fts->expandQaEntry($entry);
        if ($items === []) {
            return new Collection;
        }

        $payload = [];
        foreach ($items as $item) {
            $payload[] = [
                'content' => $item['content'],
                'content_format' => 'text',
                'qa_question_id' => $item['qa_question_id'] ?? null,
                'metadata' => [
                    'qa_role' => $item['role'],
                    'qa_answer_id' => $item['answer_id'] ?? null,
                ],
            ];
        }

        $this->purgeForQaEntry($knowledgeBase, $entry);
        $ids = $this->nodes->writeQaCanonicalNodes($knowledgeBase, $entry, $payload);
        $nodes = $this->reloadNodes($ids);

        $this->fts->indexQaEntry($knowledgeBase, $entry, $nodes);

        return $nodes;
    }

    /**
     * 重读刚写入的节点以保证后续操作拿到完整 ORM 实例（含 ULID 排序）。
     *
     * @param  list<string>  $ids
     * @return Collection<int, KnowledgeNode>
     */
    private function reloadNodes(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return KnowledgeNode::query()
            ->whereIn('id', $ids)
            ->orderByRaw('COALESCE(byte_start, 0) ASC')
            ->orderBy('id')
            ->get();
    }

    /**
     * 清空文档 canonical 节点 + 全文索引 + 大纲。向量 / RAPTOR 节点保留，
     * 由各自的索引器在重建时单独清理（避免一次写 canonical 把模型成本拉高的副作用）。
     */
    private function purgeForDocument(KnowledgeBase $knowledgeBase, KnowledgeDocument $document): void
    {
        $this->nodes->purgeStrategyForDocument($document, KnowledgeIndexingStrategy::Text);
        $this->fts->purgeForDocument($document);
    }

    /**
     * 清空问答 canonical 节点 + FTS 行。
     */
    private function purgeForQaEntry(KnowledgeBase $knowledgeBase, KnowledgeQaEntry $entry): void
    {
        $this->nodes->purgeStrategyForQaEntry($entry, KnowledgeIndexingStrategy::Text);
        $this->fts->purgeForQaEntry($entry);
    }
}
