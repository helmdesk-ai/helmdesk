<?php

namespace App\Data\Contact;

use Spatie\LaravelData\Data;

/**
 * 联系人 AI 摘要数据。
 * 显示在 resources/js/pages/inbox/InboxContextPanel.vue 的 AI 摘要 Tab，用于呈现客户概览、待关注事项、偏好和近期主题。
 */
class ContactAiSummaryData extends Data
{
    /**
     * 创建联系人 AI 摘要。
     *
     * @param  list<string>  $open_issues
     * @param  list<string>  $preferences
     * @param  list<string>  $recent_topics
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function __construct(
        public ?string $profile_summary,
        public array $open_issues,
        public array $preferences,
        public array $recent_topics,
        public ?string $source_locale,
        public array $translations,
        public ?string $updated_at,
    ) {}

    /**
     * 从 contacts.ai_context 中提取联系人 AI 摘要。
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function fromContext(?array $context): ?self
    {
        $summary = is_array($context) && is_array($context['summary'] ?? null)
            ? $context['summary']
            : null;

        if ($summary === null) {
            return null;
        }

        $profileSummary = self::stringOrNull($summary['profile_summary'] ?? null);
        $openIssues = self::stringList($summary['open_issues'] ?? []);
        $preferences = self::stringList($summary['preferences'] ?? []);
        $recentTopics = self::stringList($summary['recent_topics'] ?? []);

        if ($profileSummary === null && $openIssues === [] && $preferences === [] && $recentTopics === []) {
            return null;
        }

        return new self(
            profile_summary: $profileSummary,
            open_issues: $openIssues,
            preferences: $preferences,
            recent_topics: $recentTopics,
            source_locale: self::stringOrNull($summary['source_locale'] ?? null),
            translations: is_array($summary['translations'] ?? null) ? $summary['translations'] : [],
            updated_at: self::stringOrNull($summary['updated_at'] ?? null),
        );
    }

    /**
     * 只保留非空字符串。
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * 把任意输入整理为非空字符串列表。
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
