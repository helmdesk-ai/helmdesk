<?php

namespace App\Services\KnowledgeBase\Search;

use App\Enums\KnowledgeDocumentParseStatus;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeQaAnswer;
use App\Models\KnowledgeQaEntry;
use App\Models\KnowledgeQaQuestion;
use Illuminate\Support\Collection;

/**
 * 类 grep 检索器。
 *
 * 数据源：
 *  - 文档：KnowledgeDocument::parsed_content（解析后归一化 Markdown）；
 *  - QA：KnowledgeQaEntry::question、KnowledgeQaQuestion::question、KnowledgeQaAnswer::answer。
 *
 * 行为：
 *  - 字面 / case-insensitive 匹配（默认大小写不敏感、中文不分词）；
 *  - 每条命中给出 line / column / byte_start / byte_end，方便 Agent / 前端做精确跳转；
 *  - 不参与 RRF；hybrid 模式下作为一个独立数组返回给 Agent，由 Agent 自行判断使用方式。
 *
 * 性能：当前实现走 SQL `LIKE '%query%'` 粗筛 + PHP 精排，对小到中型知识库够用；
 * 后续若需要正则 / 多 query AND 等高级语义，再演进。
 */
class GrepRetriever
{
    /**
     * 单条命中前后保留多少字符做"上下文回显"。
     */
    private const CONTEXT_WINDOW = 80;

    /**
     * 每条 query 在每个数据源里最多返回多少条命中，避免一篇大文档把结果灌满。
     */
    private const MAX_HITS_PER_QUERY_PER_SOURCE = 8;

    /**
     * 单次检索的全局命中上限，再多就截断。
     */
    public const TOTAL_HITS_HARD_LIMIT = 50;

    /**
     * @param  list<string>  $queries
     * @param  list<string>  $knowledgeBaseIds
     * @return list<GrepMatch>
     */
    public function retrieve(string $workspaceId, array $knowledgeBaseIds, array $queries, int $topK): array
    {
        $cleanedQueries = $this->cleanQueries($queries);
        if ($cleanedQueries === [] || $knowledgeBaseIds === [] || $topK <= 0) {
            return [];
        }

        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->where('workspace_id', $workspaceId)
            ->get()
            ->keyBy('id');
        if ($knowledgeBases->isEmpty()) {
            return [];
        }

        $hits = [];
        foreach ($cleanedQueries as $query) {
            $docHits = $this->grepDocuments($workspaceId, $knowledgeBases, $query);
            $qaHits = $this->grepQaEntries($workspaceId, $knowledgeBases, $query);
            $hits = array_merge($hits, $docHits, $qaHits);
            if (count($hits) >= self::TOTAL_HITS_HARD_LIMIT) {
                $hits = array_slice($hits, 0, self::TOTAL_HITS_HARD_LIMIT);
                break;
            }
        }

        if (count($hits) > $topK) {
            $hits = array_slice($hits, 0, $topK);
        }

        return $hits;
    }

    /**
     * @param  list<string>  $queries
     * @return list<string>
     */
    private function cleanQueries(array $queries): array
    {
        $output = [];
        foreach ($queries as $query) {
            $trimmed = trim((string) $query);
            if ($trimmed === '') {
                continue;
            }
            // 去重，避免 Agent 给重复 query 后产生重复命中。
            if (! in_array($trimmed, $output, true)) {
                $output[] = $trimmed;
            }
        }

        return $output;
    }

    /**
     * @param  Collection<string, KnowledgeBase>  $knowledgeBases
     * @return list<GrepMatch>
     */
    private function grepDocuments(string $workspaceId, Collection $knowledgeBases, string $query): array
    {
        $documents = KnowledgeDocument::query()
            ->whereIn('knowledge_base_id', $knowledgeBases->keys()->all())
            ->where('parse_status', KnowledgeDocumentParseStatus::Succeeded)
            ->whereNotNull('parsed_content')
            ->whereRaw('LOWER(parsed_content) LIKE ?', ['%'.mb_strtolower($query).'%'])
            ->orderByDesc('updated_at')
            ->limit(self::TOTAL_HITS_HARD_LIMIT)
            ->get(['id', 'knowledge_base_id', 'original_filename', 'parsed_content']);

        $hits = [];
        foreach ($documents as $document) {
            $matches = $this->findMatchesInText((string) $document->parsed_content, $query, self::MAX_HITS_PER_QUERY_PER_SOURCE);
            foreach ($matches as $position) {
                $hits[] = new GrepMatch(
                    knowledgeBaseId: (string) $document->knowledge_base_id,
                    workspaceId: $workspaceId,
                    documentId: (string) $document->id,
                    documentTitle: (string) $document->original_filename,
                    qaEntryId: null,
                    qaQuestionId: null,
                    qaAnswerId: null,
                    field: 'document.parsed_content',
                    query: $query,
                    line: $position['line'],
                    column: $position['column'],
                    byteStart: $position['byte_start'],
                    byteEnd: $position['byte_end'],
                    match: $position['match'],
                    contextBefore: $position['context_before'],
                    contextAfter: $position['context_after'],
                );
                if (count($hits) >= self::TOTAL_HITS_HARD_LIMIT) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    /**
     * @param  Collection<string, KnowledgeBase>  $knowledgeBases
     * @return list<GrepMatch>
     */
    private function grepQaEntries(string $workspaceId, Collection $knowledgeBases, string $query): array
    {
        $needle = mb_strtolower($query);

        $entries = KnowledgeQaEntry::query()
            ->whereIn('knowledge_base_id', $knowledgeBases->keys()->all())
            ->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(question) LIKE ?', ['%'.$needle.'%']);
            })
            ->limit(self::TOTAL_HITS_HARD_LIMIT)
            ->get(['id', 'knowledge_base_id', 'question']);

        $hits = [];
        foreach ($entries as $entry) {
            $matches = $this->findMatchesInText((string) $entry->question, $query, self::MAX_HITS_PER_QUERY_PER_SOURCE);
            foreach ($matches as $position) {
                $hits[] = new GrepMatch(
                    knowledgeBaseId: (string) $entry->knowledge_base_id,
                    workspaceId: $workspaceId,
                    documentId: null,
                    documentTitle: null,
                    qaEntryId: (string) $entry->id,
                    qaQuestionId: null,
                    qaAnswerId: null,
                    field: 'qa_entry.question',
                    query: $query,
                    line: $position['line'],
                    column: $position['column'],
                    byteStart: $position['byte_start'],
                    byteEnd: $position['byte_end'],
                    match: $position['match'],
                    contextBefore: $position['context_before'],
                    contextAfter: $position['context_after'],
                );
                if (count($hits) >= self::TOTAL_HITS_HARD_LIMIT) {
                    return $hits;
                }
            }
        }

        $similarQuestions = KnowledgeQaQuestion::query()
            ->whereHas('entry', static function ($q) use ($knowledgeBases): void {
                $q->whereIn('knowledge_base_id', $knowledgeBases->keys()->all());
            })
            ->whereRaw('LOWER(question) LIKE ?', ['%'.$needle.'%'])
            ->with('entry:id,knowledge_base_id')
            ->limit(self::TOTAL_HITS_HARD_LIMIT)
            ->get(['id', 'knowledge_qa_entry_id', 'question']);

        foreach ($similarQuestions as $question) {
            /** @var KnowledgeQaQuestion $question */
            $entry = $question->entry;
            if ($entry === null) {
                continue;
            }
            $matches = $this->findMatchesInText((string) $question->question, $query, self::MAX_HITS_PER_QUERY_PER_SOURCE);
            foreach ($matches as $position) {
                $hits[] = new GrepMatch(
                    knowledgeBaseId: (string) $entry->knowledge_base_id,
                    workspaceId: $workspaceId,
                    documentId: null,
                    documentTitle: null,
                    qaEntryId: (string) $entry->id,
                    qaQuestionId: (string) $question->id,
                    qaAnswerId: null,
                    field: 'qa_entry.similar_question',
                    query: $query,
                    line: $position['line'],
                    column: $position['column'],
                    byteStart: $position['byte_start'],
                    byteEnd: $position['byte_end'],
                    match: $position['match'],
                    contextBefore: $position['context_before'],
                    contextAfter: $position['context_after'],
                );
                if (count($hits) >= self::TOTAL_HITS_HARD_LIMIT) {
                    return $hits;
                }
            }
        }

        $answers = KnowledgeQaAnswer::query()
            ->whereHas('entry', static function ($q) use ($knowledgeBases): void {
                $q->whereIn('knowledge_base_id', $knowledgeBases->keys()->all());
            })
            ->whereRaw('LOWER(answer) LIKE ?', ['%'.$needle.'%'])
            ->with('entry:id,knowledge_base_id')
            ->limit(self::TOTAL_HITS_HARD_LIMIT)
            ->get(['id', 'knowledge_qa_entry_id', 'answer']);

        foreach ($answers as $answer) {
            /** @var KnowledgeQaAnswer $answer */
            $entry = $answer->entry;
            if ($entry === null) {
                continue;
            }
            $matches = $this->findMatchesInText((string) $answer->answer, $query, self::MAX_HITS_PER_QUERY_PER_SOURCE);
            foreach ($matches as $position) {
                $hits[] = new GrepMatch(
                    knowledgeBaseId: (string) $entry->knowledge_base_id,
                    workspaceId: $workspaceId,
                    documentId: null,
                    documentTitle: null,
                    qaEntryId: (string) $entry->id,
                    qaQuestionId: null,
                    qaAnswerId: (string) $answer->id,
                    field: 'qa_entry.answer',
                    query: $query,
                    line: $position['line'],
                    column: $position['column'],
                    byteStart: $position['byte_start'],
                    byteEnd: $position['byte_end'],
                    match: $position['match'],
                    contextBefore: $position['context_before'],
                    contextAfter: $position['context_after'],
                );
                if (count($hits) >= self::TOTAL_HITS_HARD_LIMIT) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    /**
     * 在文本中按字面（大小写不敏感）寻找 query，返回带行号 / 列号 / 上下文的匹配描述。
     *
     * @return list<array{
     *     line: int,
     *     column: int,
     *     byte_start: int,
     *     byte_end: int,
     *     match: string,
     *     context_before: string,
     *     context_after: string,
     * }>
     */
    private function findMatchesInText(string $haystack, string $needle, int $maxMatches): array
    {
        if ($haystack === '' || $needle === '' || $maxMatches <= 0) {
            return [];
        }

        $lowerHaystack = mb_strtolower($haystack);
        $lowerNeedle = mb_strtolower($needle);
        $haystackLen = strlen($haystack);

        // 使用字节级 strpos 找命中起点；同时维护 multibyte 字符意义下的"行号/列号"。
        $byteOffset = 0;
        $line = 1;
        $column = 1;
        $cursor = 0;
        $needleByteLen = strlen($lowerNeedle);
        if ($needleByteLen === 0) {
            return [];
        }

        $matches = [];
        while ($cursor < $haystackLen) {
            $hitByte = strpos($lowerHaystack, $lowerNeedle, $cursor);
            if ($hitByte === false) {
                break;
            }

            // 把 cursor → hitByte 之间未数过的字符消化掉，更新行 / 列。
            while ($byteOffset < $hitByte) {
                $char = $haystack[$byteOffset];
                $size = $this->utf8CharLength($char);
                if ($char === "\n") {
                    $line++;
                    $column = 1;
                } else {
                    $column++;
                }
                $byteOffset += $size;
            }

            $endByte = $hitByte + $needleByteLen;
            $matchSubstr = substr($haystack, $hitByte, $needleByteLen);

            $beforeStart = max(0, $hitByte - self::CONTEXT_WINDOW);
            $contextBefore = substr($haystack, $beforeStart, $hitByte - $beforeStart);
            $afterEnd = min($haystackLen, $endByte + self::CONTEXT_WINDOW);
            $contextAfter = substr($haystack, $endByte, $afterEnd - $endByte);

            $matches[] = [
                'line' => $line,
                'column' => $column,
                'byte_start' => $hitByte,
                'byte_end' => $endByte,
                'match' => $matchSubstr,
                'context_before' => $contextBefore,
                'context_after' => $contextAfter,
            ];

            if (count($matches) >= $maxMatches) {
                break;
            }

            // 跳过本次命中的范围，继续搜下一处。
            $cursor = $endByte;
            // 同步推进 line/column 到 endByte，避免在下一处命中前的 catch-up 计算重头开始。
            while ($byteOffset < $endByte) {
                $char = $haystack[$byteOffset];
                $size = $this->utf8CharLength($char);
                if ($char === "\n") {
                    $line++;
                    $column = 1;
                } else {
                    $column++;
                }
                $byteOffset += $size;
            }
        }

        return $matches;
    }

    /**
     * 返回 UTF-8 编码下首字节对应的字符字节长度（1/2/3/4）。
     */
    private function utf8CharLength(string $firstByte): int
    {
        $byte = ord($firstByte);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0) {
            return 4;
        }

        return 1;
    }
}
