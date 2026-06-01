<?php

namespace App\Data\Conversation;

use Spatie\LaravelData\Data;

/**
 * 会话 AI 摘要生成结果。
 * 由 Go AI 运行时返回给 GenerateConversationSummaryAction，用于写入会话摘要和联系人记忆输入。
 */
class GeneratedConversationSummaryData extends Data
{
    /**
     * 创建会话摘要生成结果。
     *
     * @param  list<string>  $topics
     * @param  list<string>  $open_issues
     * @param  list<string>  $preferences
     */
    public function __construct(
        public string $summary,
        public array $topics,
        public array $open_issues,
        public array $preferences,
    ) {}

    /**
     * 从 Go 响应数组创建生成结果。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            summary: trim((string) ($payload['summary'] ?? '')),
            topics: self::stringList($payload['topics'] ?? []),
            open_issues: self::stringList($payload['open_issues'] ?? []),
            preferences: self::stringList($payload['preferences'] ?? []),
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
