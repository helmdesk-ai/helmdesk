<?php

namespace App\Data\KnowledgeBase;

use Spatie\LaravelData\Data;

/**
 * 召回测试面板里的单条 grep 字面命中。
 * 由 RunKnowledgeRecallTestAction 在 GrepMatch 基础上补全来源标题与字段标签后下发，
 * 对应 resources/js/pages/knowledgeBase/KnowledgeRecallTestPanel.vue 的 grep 结果列表。
 */
class KnowledgeRecallGrepMatchData extends Data
{
    public function __construct(
        public string $origin_type,
        public ?string $origin_title,
        public string $field,
        public string $field_label,
        public int $line,
        public int $column,
        public string $context_before,
        public string $match,
        public string $context_after,
        public ?string $heading_path,
    ) {}

    /**
     * 把内部 GrepMatch::toArray() 的单条结构转换为面板用 DTO。
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, string>  $qaQuestions  qa_entry_id => 主问题
     */
    public static function fromMatch(array $match, array $qaQuestions): self
    {
        $qaEntryId = $match['qa_entry_id'] ?? null;

        if ($qaEntryId !== null) {
            $originType = 'qa';
            $originTitle = $qaQuestions[$qaEntryId] ?? null;
        } else {
            $originType = 'document';
            $originTitle = $match['document_title'] ?? null;
        }

        $field = (string) ($match['field'] ?? '');

        return new self(
            origin_type: $originType,
            origin_title: $originTitle,
            field: $field,
            field_label: self::resolveFieldLabel($field),
            line: (int) ($match['line'] ?? 0),
            column: (int) ($match['column'] ?? 0),
            context_before: (string) ($match['context_before'] ?? ''),
            match: (string) ($match['match'] ?? ''),
            context_after: (string) ($match['context_after'] ?? ''),
            heading_path: $match['heading_path'] ?? null,
        );
    }

    /**
     * 将命中字段标识翻译成可读标签；缺失翻译时回退原始标识。
     */
    private static function resolveFieldLabel(string $field): string
    {
        $key = 'knowledge_recall.fields.'.$field;
        $label = __($key);

        return $label === $key ? $field : $label;
    }
}
