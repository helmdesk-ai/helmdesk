<?php

namespace App\Data\Reception\Plan;

use App\Enums\ConversationAutoMessageTrigger;
use LogicException;
use Spatie\LaravelData\Data;

/**
 * 接待方案自动回复配置集合。
 * 随接待方案草稿保存，并在发布时写入 ReceptionPlanVersion snapshot_config。
 */
class AutoMessagesConfigData extends Data
{
    public const DEFAULT_CONFIG = [
        'ai_welcome' => ['enabled' => true, 'message' => '您好，我是{{display_name}}，请问有什么可以帮您？'],
        'teammate_joined' => ['enabled' => true, 'message' => '您好，我是{{teammate_name}}，接下来由我为您服务。'],
        'teammate_transferred' => ['enabled' => true, 'message' => '您好，我是{{teammate_name}}，已接手本次会话。'],
    ];

    /**
     * 创建自动回复配置集合。
     */
    public function __construct(
        public AutoMessageConfigData $ai_welcome,
        public AutoMessageConfigData $teammate_joined,
        public AutoMessageConfigData $teammate_transferred,
    ) {}

    /**
     * 从草稿或版本快照恢复配置。
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            ai_welcome: AutoMessageConfigData::fromArray(self::triggerConfig($raw, ConversationAutoMessageTrigger::AiWelcome)),
            teammate_joined: AutoMessageConfigData::fromArray(self::triggerConfig($raw, ConversationAutoMessageTrigger::TeammateJoined)),
            teammate_transferred: AutoMessageConfigData::fromArray(self::triggerConfig($raw, ConversationAutoMessageTrigger::TeammateTransferred)),
        );
    }

    /**
     * 取指定触发点配置。
     */
    public function forTrigger(ConversationAutoMessageTrigger $trigger): AutoMessageConfigData
    {
        return match ($trigger) {
            ConversationAutoMessageTrigger::AiWelcome => $this->ai_welcome,
            ConversationAutoMessageTrigger::TeammateJoined => $this->teammate_joined,
            ConversationAutoMessageTrigger::TeammateTransferred => $this->teammate_transferred,
        };
    }

    /**
     * 返回可写入 JSON 的配置数组。
     *
     * @return array<string, array{enabled: bool, message: ?string}>
     */
    public function toConfigArray(): array
    {
        return [
            ConversationAutoMessageTrigger::AiWelcome->value => $this->ai_welcome->toConfigArray(),
            ConversationAutoMessageTrigger::TeammateJoined->value => $this->teammate_joined->toConfigArray(),
            ConversationAutoMessageTrigger::TeammateTransferred->value => $this->teammate_transferred->toConfigArray(),
        ];
    }

    /**
     * 提取指定触发点的原始配置数组。
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function triggerConfig(array $raw, ConversationAutoMessageTrigger $trigger): array
    {
        $value = $raw[$trigger->value] ?? null;
        if (! is_array($value)) {
            throw new LogicException("Auto message config [{$trigger->value}] is required.");
        }

        return $value;
    }
}
