<?php

namespace App\Data\Reception\Plan;

use Spatie\LaravelData\Data;

/**
 * 接待方案自动回复单项配置。
 * 对应接待方案编辑页“自动回复”区域的一个触发点。
 */
class AutoMessageConfigData extends Data
{
    /**
     * 创建自动回复单项配置。
     */
    public function __construct(
        public bool $enabled,
        public ?string $message,
    ) {}

    /**
     * 从配置数组恢复单项配置。
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $message = $raw['message'] === null ? null : trim($raw['message']);

        return new self(
            enabled: $raw['enabled'],
            message: filled($message) ? $message : null,
        );
    }

    /**
     * 返回可写入 JSON 快照的数组。
     *
     * @return array{enabled: bool, message: ?string}
     */
    public function toConfigArray(): array
    {
        $message = $this->message === null ? null : trim($this->message);

        return [
            'enabled' => $this->enabled,
            'message' => filled($message) ? $message : null,
        ];
    }
}
