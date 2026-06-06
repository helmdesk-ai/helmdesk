<?php

namespace App\Services\KnowledgeBase\Parsing;

use App\Enums\KnowledgeChunkingStrategy;
use App\Exceptions\BusinessException;
use App\Models\SystemContext;
use App\Services\KnowledgeBase\KnowledgeEmbeddingService;

/**
 * Markdown 分段编排：按系统配置统一产出"可索引段"。
 *
 * - fixed 策略：纯结构化分段（段落级、累加到 max_tokens、按 overlap 软回滚）。
 * - semantic 策略：先用 sentenceUnits 拆句、调嵌入模型，再按余弦相似度合并相邻句子。
 *
 * 任何上游（Vector / Raptor / FullText）只要拿到 systemContext 即可获得"统一形状"的分段列表，
 * 避免每个索引器各自硬编码 chunk 尺寸或丢失 heading_path。
 *
 * @phpstan-type PlannedSegment array{
 *     content: string,
 *     heading_path: list<string>,
 *     byte_start: int,
 *     byte_end: int,
 *     token_count: int,
 * }
 */
class MarkdownChunkPlanner
{
    /**
     * 相邻句子余弦相似度低于该阈值即在此处切段。
     */
    private const SEMANTIC_SIMILARITY_THRESHOLD = 0.72;

    public function __construct(
        private readonly MarkdownChunker $chunker,
        private readonly KnowledgeEmbeddingService $embedder,
    ) {}

    /**
     * 按系统配置切分 markdown，统一返回带 heading_path 的段列表。
     *
     * @return list<PlannedSegment>
     */
    public function plan(SystemContext $systemContext, string $markdown): array
    {
        $maxTokens = max(1, (int) $systemContext->knowledge_chunk_max_tokens);
        $overlapTokens = max(0, (int) $systemContext->knowledge_chunk_overlap_tokens);

        if ($systemContext->knowledge_chunking_strategy === KnowledgeChunkingStrategy::Semantic) {
            return $this->planSemantic($systemContext, $markdown, $maxTokens);
        }

        return $this->chunker->chunk($markdown, $maxTokens, $overlapTokens)['segments'];
    }

    /**
     * semantic 策略：以句子为最小单元，按句间相似度向后累积，超过窗口或语义跳变即落段。
     *
     * @return list<PlannedSegment>
     */
    private function planSemantic(SystemContext $systemContext, string $markdown, int $maxTokens): array
    {
        $units = $this->chunker->sentenceUnits($markdown);
        if ($units === []) {
            return [];
        }

        $embeddingModel = $systemContext->knowledgeEmbeddingModel;
        if ($embeddingModel === null || $embeddingModel->provider === null) {
            throw new BusinessException(__('knowledge_base.messages.invalid_embedding_model'));
        }

        $sentences = array_map(static fn (array $unit): string => (string) $unit['content'], $units);
        [, $vectors] = $this->embedder->embedTexts($embeddingModel, $sentences);

        $segments = [];
        $bufferUnits = [];
        $bufferVectors = [];
        $bufferTokens = 0;

        $flush = function () use (&$segments, &$bufferUnits, &$bufferVectors, &$bufferTokens): void {
            if ($bufferUnits === []) {
                return;
            }
            $first = $bufferUnits[0];
            $last = $bufferUnits[array_key_last($bufferUnits)];
            $segments[] = [
                'content' => implode(' ', array_map(
                    static fn (array $unit): string => (string) $unit['content'],
                    $bufferUnits,
                )),
                'heading_path' => $first['heading_path'],
                'byte_start' => (int) $first['byte_start'],
                'byte_end' => (int) $last['byte_end'],
                'token_count' => $bufferTokens,
            ];
            $bufferUnits = [];
            $bufferVectors = [];
            $bufferTokens = 0;
        };

        foreach ($units as $index => $unit) {
            $unitTokens = (int) $unit['token_count'];
            $vector = $vectors[$index] ?? [];

            if ($bufferUnits !== []) {
                $similarity = $this->cosineSimilarity($this->averageVector($bufferVectors), $vector);
                if ($bufferTokens + $unitTokens > $maxTokens || $similarity < self::SEMANTIC_SIMILARITY_THRESHOLD) {
                    $flush();
                }
            }

            $bufferUnits[] = $unit;
            $bufferVectors[] = $vector;
            $bufferTokens += $unitTokens;
        }

        $flush();

        return $segments;
    }

    /**
     * 把段的 heading_path 数组压成"A › B › C"形式，留给写入仓库做展示。
     */
    public function joinHeadingPath(mixed $headingPath): ?string
    {
        if (! is_array($headingPath) || $headingPath === []) {
            return null;
        }
        $clean = array_values(array_filter(array_map(
            static fn ($p) => is_string($p) ? trim($p) : '',
            $headingPath,
        ), static fn (string $p) => $p !== ''));

        return $clean === [] ? null : implode(' › ', $clean);
    }

    /**
     * @param  list<list<float>>  $vectors
     * @return list<float>
     */
    private function averageVector(array $vectors): array
    {
        $count = count($vectors);
        if ($count === 0) {
            return [];
        }

        $sum = [];
        foreach ($vectors as $vector) {
            foreach ($vector as $index => $value) {
                $sum[$index] = ($sum[$index] ?? 0.0) + (float) $value;
            }
        }

        return array_map(static fn (float $value): float => $value / $count, $sum);
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function cosineSimilarity(array $left, array $right): float
    {
        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;
        $length = min(count($left), count($right));

        for ($index = 0; $index < $length; $index++) {
            $leftValue = (float) $left[$index];
            $rightValue = (float) $right[$index];
            $dot += $leftValue * $rightValue;
            $leftNorm += $leftValue * $leftValue;
            $rightNorm += $rightValue * $rightValue;
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }
}
