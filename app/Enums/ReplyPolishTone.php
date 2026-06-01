<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 收件箱回复 AI 润色使用的语气选项，由后端统一下发给前端选择器。
 */
enum ReplyPolishTone: string implements LabeledEnum
{
    case Keep = 'keep';
    case Professional = 'professional';
    case Friendly = 'friendly';
    case Concise = 'concise';

    /**
     * 返回语气选项的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Keep => __('conversation.reply_polish_tones.keep'),
            self::Professional => __('conversation.reply_polish_tones.professional'),
            self::Friendly => __('conversation.reply_polish_tones.friendly'),
            self::Concise => __('conversation.reply_polish_tones.concise'),
        };
    }
}
