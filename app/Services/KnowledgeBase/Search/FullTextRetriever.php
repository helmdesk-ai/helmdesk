<?php

namespace App\Services\KnowledgeBase\Search;

use App\Models\KnowledgeNode;
use Illuminate\Support\Facades\DB;

/**
 * 全文召回器。
 *
 * 流程：
 *  - 把每条原始 query 经过 KnowledgeTokenizer 切成 token 列表；
 *  - 用 FTS5 MATCH 表达式做 OR 召回：`token1 OR token2 OR ...`；
 *  - 通过 -bm25() 把 bm25 排序得分转成"越大越相关"，再 LIMIT topK；
 *  - 用 workspace / knowledge_base_id 双重 UNINDEXED 列过滤，避免跨库泄漏。
 *
 * 中文友好性来自两处：
 *  - 写入时 search_content 已被预分词成空格分隔 token；
 *  - 查询时同样的 tokenizer 把用户输入切成 token，再拼成 MATCH OR 表达式。
 */
class FullTextRetriever
{
    /**
     * MATCH 表达式中最大允许的 OR token 数，避免单条 query 引爆 FTS。
     */
    private const MAX_QUERY_TOKENS = 16;

    public function __construct(
        private readonly KnowledgeTokenizer $tokenizer,
    ) {}

    /**
     * 在指定知识库范围内做全文召回。
     *
     * @param  list<string>  $queries
     * @param  list<string>  $knowledgeBaseIds
     * @return list<KnowledgeSearchHit>
     */
    public function retrieve(
        string $workspaceId,
        array $knowledgeBaseIds,
        array $queries,
        int $topK,
    ): array {
        if ($queries === [] || $knowledgeBaseIds === [] || $topK <= 0) {
            return [];
        }

        // 把每条 query 跑出来的 FTS 命中行先存到 $perQuery，所有命中的 node_id 汇总到 $allNodeIds，
        // 后面统一一次 whereIn 把 KnowledgeNode 模型拉回来。
        /** @var list<array{queryIndex: int, expression: string, rows: list<object>}> $perQuery */
        $perQuery = [];
        $allNodeIds = [];
        foreach ($queries as $queryIndex => $query) {
            $expression = $this->buildMatchExpression($query);
            if ($expression === null) {
                continue;
            }
            $rows = $this->runMatch($expression, $workspaceId, $knowledgeBaseIds, $topK);
            if ($rows === []) {
                continue;
            }
            $perQuery[] = ['queryIndex' => $queryIndex, 'expression' => $expression, 'rows' => $rows];
            foreach ($rows as $row) {
                $allNodeIds[$row->node_id] = true;
            }
        }

        if ($perQuery === []) {
            return [];
        }

        $nodes = KnowledgeNode::query()
            ->whereIn('id', array_keys($allNodeIds))
            ->get()
            ->keyBy('id');

        $hits = [];
        foreach ($perQuery as $bucket) {
            $rank = 0;
            foreach ($bucket['rows'] as $row) {
                $node = $nodes->get($row->node_id);
                if ($node === null) {
                    continue;
                }
                $rank++;
                $hits[] = new KnowledgeSearchHit(
                    source: 'fulltext',
                    score: (float) $row->bm25_score,
                    rank: $rank,
                    knowledgeNodeId: $node->id,
                    knowledgeBaseId: $node->knowledge_base_id,
                    workspaceId: $node->workspace_id,
                    documentId: $node->document_id,
                    qaEntryId: $node->qa_entry_id,
                    qaQuestionId: $node->qa_question_id,
                    headingPath: $node->heading_path,
                    byteStart: $node->byte_start,
                    byteEnd: $node->byte_end,
                    content: $node->content,
                    metadata: [
                        'query_index' => $bucket['queryIndex'],
                        'expression' => $bucket['expression'],
                        'strategy' => $node->strategy->value,
                    ],
                );
                if ($rank >= $topK) {
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * 把用户 query 拼成 FTS5 MATCH OR 表达式；返回 null 表示该 query 分词后全是停用词，跳过。
     */
    private function buildMatchExpression(string $query): ?string
    {
        $tokens = $this->tokenizer->queryTokens($query);
        if ($tokens === []) {
            return null;
        }
        // 截断到 MAX_QUERY_TOKENS，按 token 出现顺序保留，覆盖最重要的部分。
        if (count($tokens) > self::MAX_QUERY_TOKENS) {
            $tokens = array_slice($tokens, 0, self::MAX_QUERY_TOKENS);
        }

        $quoted = array_map(static function (string $token): string {
            // FTS5 phrase quoting: 把 token 包成 "..."，并把内部的双引号 escape 成 ""，
            // 这样无论是带英文 / 中文 / 数字 / 标点的 token 都不会触发 FTS5 表达式语法。
            $escaped = str_replace('"', '""', $token);

            return '"'.$escaped.'"';
        }, $tokens);

        return implode(' OR ', $quoted);
    }

    /**
     * 执行 FTS5 检索；按 bm25 升序（更小更相关），再反转得分让上层 RRF 直接按"越大越好"使用。
     *
     * @param  list<string>  $knowledgeBaseIds
     * @return list<object>
     */
    private function runMatch(string $expression, string $workspaceId, array $knowledgeBaseIds, int $topK): array
    {
        $placeholders = implode(',', array_fill(0, count($knowledgeBaseIds), '?'));
        $sql = <<<SQL
            SELECT node_id,
                   document_id,
                   qa_entry_id,
                   knowledge_base_id,
                   workspace_id,
                   byte_start,
                   byte_end,
                   heading_path,
                   content,
                   -bm25(knowledge_fts) AS bm25_score
            FROM knowledge_fts
            WHERE knowledge_fts MATCH ?
              AND workspace_id = ?
              AND knowledge_base_id IN ({$placeholders})
            ORDER BY bm25_score DESC
            LIMIT ?
        SQL;

        $bindings = array_merge([$expression, $workspaceId], $knowledgeBaseIds, [$topK]);

        return DB::connection('sqlite_rag')->select($sql, $bindings);
    }
}
