<?php

namespace App\Services\KnowledgeBase;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeQaAnswer;
use App\Models\KnowledgeQaEntry;
use App\Models\KnowledgeQaQuestion;
use App\Services\KnowledgeBase\Search\KnowledgeTokenizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 全文索引仓库。
 *
 * 负责把 canonical `strategy=text` 节点（来自 WriteCanonicalChunksAction）同步进 FTS5 虚表 knowledge_fts。
 * 每条 FTS 行的标识与 knowledge_nodes.id 对齐，方便混合检索时直接按 node_id 做 RRF 合并；
 * 原文 `content` 仅作 UNINDEXED 回展，真正参与索引的是经 KnowledgeTokenizer 预分词后的 `search_content`。
 */
class KnowledgeFullTextRepository
{
    public function __construct(
        private readonly KnowledgeTokenizer $tokenizer,
    ) {}

    /**
     * 清空指定文档的全文索引与大纲记录。
     */
    public function purgeForDocument(KnowledgeDocument $document): void
    {
        DB::connection('sqlite_rag')->statement(
            'DELETE FROM knowledge_fts WHERE document_id = ?',
            [(string) $document->id],
        );

        DB::connection('sqlite_rag')->table('knowledge_outlines')
            ->where('document_id', $document->id)
            ->delete();
    }

    /**
     * 清空指定问答条目的全文索引。
     */
    public function purgeForQaEntry(KnowledgeQaEntry $entry): void
    {
        DB::connection('sqlite_rag')->statement(
            'DELETE FROM knowledge_fts WHERE qa_entry_id = ?',
            [(string) $entry->id],
        );
    }

    /**
     * 删除知识库时清空它名下的全文索引与大纲。
     */
    public function purgeForKnowledgeBase(KnowledgeBase $knowledgeBase): void
    {
        DB::connection('sqlite_rag')->statement(
            'DELETE FROM knowledge_fts WHERE knowledge_base_id = ?',
            [(string) $knowledgeBase->id],
        );

        DB::connection('sqlite_rag')->table('knowledge_outlines')
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->delete();
    }

    /**
     * 按文档批量写入 FTS 行；调用方负责传入已经写好的 canonical 节点。
     *
     * @param  iterable<KnowledgeNode>  $nodes
     * @param  array<int, mixed>|null  $outline  Markdown 大纲树
     */
    public function indexDocument(
        KnowledgeBase $knowledgeBase,
        KnowledgeDocument $document,
        iterable $nodes,
        ?array $outline,
    ): void {
        DB::connection('sqlite_rag')->transaction(function () use ($knowledgeBase, $document, $nodes, $outline): void {
            $this->purgeForDocument($document);

            foreach ($nodes as $node) {
                $this->insertNodeRow(
                    knowledgeBase: $knowledgeBase,
                    node: $node,
                    documentId: (string) $document->id,
                    groupId: (string) $document->group_id,
                );
            }

            if (is_array($outline) && $outline !== []) {
                $now = now();
                DB::connection('sqlite_rag')->table('knowledge_outlines')->insert([
                    'id' => (string) Str::ulid(),
                    'knowledge_base_id' => (string) $knowledgeBase->id,
                    'document_id' => (string) $document->id,
                    'outline' => json_encode($outline, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * 按问答条目批量写入 FTS 行；同样要求节点已经在 WriteCanonicalChunksAction 中写好。
     *
     * @param  iterable<KnowledgeNode>  $nodes
     */
    public function indexQaEntry(KnowledgeBase $knowledgeBase, KnowledgeQaEntry $entry, iterable $nodes): void
    {
        DB::connection('sqlite_rag')->transaction(function () use ($knowledgeBase, $entry, $nodes): void {
            $this->purgeForQaEntry($entry);

            foreach ($nodes as $node) {
                $this->insertNodeRow(
                    knowledgeBase: $knowledgeBase,
                    node: $node,
                    documentId: null,
                    groupId: (string) $entry->group_id,
                );
            }
        });
    }

    /**
     * 单条 canonical 节点 → 单行 FTS 记录。
     *
     * indexed 列只有 search_content 和 heading_path；
     * search_content 经 KnowledgeTokenizer 预分词，heading_path 同样过 tokenizer 以便"按标题词命中"。
     */
    private function insertNodeRow(
        KnowledgeBase $knowledgeBase,
        KnowledgeNode $node,
        ?string $documentId,
        ?string $groupId,
    ): void {
        $rawContent = (string) $node->content;
        $searchContent = $this->tokenizer->indexable($rawContent);
        $headingPath = (string) ($node->heading_path ?? '');
        $headingTokens = $headingPath === '' ? '' : $this->tokenizer->indexable($headingPath);

        DB::connection('sqlite_rag')->table('knowledge_fts')->insert([
            'search_content' => $searchContent,
            'heading_path' => $headingTokens,
            'content' => $rawContent,
            'node_id' => (string) $node->id,
            'document_id' => $documentId,
            'qa_entry_id' => $node->qa_entry_id !== null ? (string) $node->qa_entry_id : null,
            'qa_question_id' => $node->qa_question_id !== null ? (string) $node->qa_question_id : null,
            'knowledge_base_id' => (string) $knowledgeBase->id,
            'group_id' => $groupId,
            'byte_start' => $node->byte_start,
            'byte_end' => $node->byte_end,
        ]);
    }

    /**
     * 工具：把一条问答条目展开成"写 canonical 节点用"的纯文本列表（主问题 / 相似问法 / 已启用答案）。
     *
     * @return list<array{
     *     content: string,
     *     heading_path: string,
     *     qa_question_id: string|null,
     *     answer_id: string|null,
     *     role: string,
     * }>
     */
    public function expandQaEntry(KnowledgeQaEntry $entry): array
    {
        $entry->loadMissing(['similarQuestions', 'answers']);

        $payload = [
            [
                'content' => (string) $entry->question,
                'heading_path' => 'qa.primary_question',
                'qa_question_id' => null,
                'answer_id' => null,
                'role' => 'qa_primary',
            ],
        ];

        foreach ($entry->similarQuestions as $question) {
            /** @var KnowledgeQaQuestion $question */
            $payload[] = [
                'content' => (string) $question->question,
                'heading_path' => 'qa.similar_question',
                'qa_question_id' => (string) $question->id,
                'answer_id' => null,
                'role' => 'qa_similar',
            ];
        }

        foreach ($entry->answers->where('is_enabled', true) as $answer) {
            /** @var KnowledgeQaAnswer $answer */
            $payload[] = [
                'content' => (string) $answer->answer,
                'heading_path' => 'qa.answer',
                'qa_question_id' => null,
                'answer_id' => (string) $answer->id,
                'role' => 'qa_answer',
            ];
        }

        return $payload;
    }
}
