<?php

namespace App\Data\Contact;

use Spatie\LaravelData\Data;

/**
 * 联系人 AI 摘要生成结果。
 * 由 Go AI 运行时返回，写入 contacts.ai_context.summary。
 */
class GeneratedContactAiSummaryData extends Data
{
    /**
     * 创建联系人 AI 摘要生成结果。
     *
     * @param  list<string>  $open_issues
     * @param  list<string>  $preferences
     * @param  list<string>  $recent_topics
     */
    public function __construct(
        public string $profile_summary,
        public array $open_issues,
        public array $preferences,
        public array $recent_topics,
    ) {}

    /**
     * 从 Go 响应数组创建生成结果。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            profile_summary: trim((string) ($payload['profile_summary'] ?? '')),
            open_issues: self::stringList($payload['open_issues'] ?? []),
            preferences: self::stringList($payload['preferences'] ?? []),
            recent_topics: self::stringList($payload['recent_topics'] ?? []),
        );
    }

    /**
     * 将任意数组清理为非空字符串列表。
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
