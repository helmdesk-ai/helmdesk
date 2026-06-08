<?php

namespace App\Data\Reception\Plan;

use App\Enums\AutoMessageTranslationFailureMode;
use App\Models\Conversation;
use Spatie\LaravelData\Data;

/**
 * 接待方案内访客侧预设文案的翻译策略。
 */
class ReceptionMessageTranslationConfigData extends Data
{
    public const DEFAULT_CONFIG = [
        'enabled' => false,
        'failure_mode' => AutoMessageTranslationFailureMode::Skip->value,
    ];

    /**
     * 创建访客侧预设文案翻译策略。
     *
     * 不再指定具体翻译供应商：启用后由全局翻译轮询池随机取用、失败轮询（见 TranslationProviderPool）。
     * enabled 为 false 时该方案不做任何翻译。
     */
    public function __construct(
        public bool $enabled = false,
        public AutoMessageTranslationFailureMode $failure_mode = AutoMessageTranslationFailureMode::Skip,
    ) {}

    /**
     * 从草稿或版本快照恢复配置；缺失时使用当前默认策略。
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        $raw ??= [];

        $failureMode = AutoMessageTranslationFailureMode::tryFrom((string) ($raw['failure_mode'] ?? ''))
            ?? AutoMessageTranslationFailureMode::Skip;

        return new self(
            enabled: filter_var($raw['enabled'] ?? self::DEFAULT_CONFIG['enabled'], FILTER_VALIDATE_BOOLEAN),
            failure_mode: $failureMode,
        );
    }

    /**
     * 从会话锁定的接待方案版本快照恢复翻译配置。
     */
    public static function fromConversation(Conversation $conversation): self
    {
        $snapshot = $conversation->receptionPlanVersion()->first()?->snapshot_config;

        return self::fromSnapshot(is_array($snapshot) ? $snapshot : null);
    }

    /**
     * 从版本快照数组中恢复翻译配置。
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function fromSnapshot(?array $snapshot): self
    {
        $raw = $snapshot['translation_config'] ?? null;

        return self::fromArray(is_array($raw) ? $raw : null);
    }

    /**
     * 返回可写入草稿和版本快照的数组。
     *
     * @return array{enabled: bool, failure_mode: string}
     */
    public function toConfigArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'failure_mode' => $this->failure_mode->value,
        ];
    }
}
