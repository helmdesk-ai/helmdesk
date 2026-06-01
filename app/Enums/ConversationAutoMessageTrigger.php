<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 会话自动回复触发点，用于标记由系统按接待流程自动写入的消息。
 */
enum ConversationAutoMessageTrigger: string implements LabeledEnum
{
    case AiWelcome = 'ai_welcome';
    case TeammateJoined = 'teammate_joined';
    case TeammateTransferred = 'teammate_transferred';

    /**
     * 返回自动回复触发点的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::AiWelcome => __('conversation.auto_message_triggers.ai_welcome'),
            self::TeammateJoined => __('conversation.auto_message_triggers.teammate_joined'),
            self::TeammateTransferred => __('conversation.auto_message_triggers.teammate_transferred'),
        };
    }
}
