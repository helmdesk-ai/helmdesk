<?php

namespace App\Data\KnowledgeBase;

use Spatie\LaravelData\Data;

/**
 * 召回测试面板里的单条语义命中。
 * 由 RunKnowledgeRecallTestAction 在 KnowledgeSearchHit 基础上富集来源标题后下发，
 * 对应 resources/js/pages/knowledgeBase/KnowledgeRecallTestPanel.vue 的语义结果列表。
 */
class KnowledgeRecallSemanticHitData extends Data
{
    public function __construct(
        public int $rank,
        public string $source,
        public string $source_label,
        public float $score,
        public string $origin_type,
        public ?string $origin_title,
        public ?string $heading_path,
        public string $content,
    ) {}

    /**
     * 把内部 KnowledgeSearchHit::toArray() 的单条结构转换为面板用 DTO。
     *
     * @param  array<string, mixed>  $hit
     * @param  array<string, string>  $documentTitles  document_id => 文件名
     * @param  array<string, string>  $qaQuestions  qa_entry_id => 主问题
     */
    public static function fromHit(array $hit, array $documentTitles, array $qaQuestions): self
    {
        $qaEntryId = $hit['qa_entry_id'] ?? null;
        $documentId = $hit['document_id'] ?? null;
        $source = (string) ($hit['source'] ?? '');

        if ($qaEntryId !== null) {
            $originType = 'qa';
            $originTitle = $qaQuestions[$qaEntryId] ?? null;
        } else {
            $originType = 'document';
            $originTitle = $documentId !== null ? ($documentTitles[$documentId] ?? null) : null;
        }

        return new self(
            rank: (int) ($hit['rank'] ?? 0),
            source: $source,
            source_label: self::resolveSourceLabel($source),
            score: (float) ($hit['score'] ?? 0),
            origin_type: $originType,
            origin_title: $originTitle,
            heading_path: $hit['heading_path'] ?? null,
            content: (string) ($hit['content'] ?? ''),
        );
    }

    /**
     * 将 retriever 来源标识翻译成可读标签；缺失翻译时回退原始标识。
     */
    private static function resolveSourceLabel(string $source): string
    {
        $key = 'knowledge_recall.sources.'.$source;
        $label = __($key);

        return $label === $key ? $source : $label;
    }
}
