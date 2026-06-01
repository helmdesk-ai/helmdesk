<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 自动回复翻译失败后的访客侧发送策略。
 */
enum AutoMessageTranslationFailureMode: string implements LabeledEnum
{
    case Skip = 'skip';
    case SendOriginal = 'send_original';

    /**
     * 返回设置页下拉展示文案。
     */
    public function label(): string
    {
        return match ($this) {
            self::Skip => __('translation.auto_message_failure_modes.skip.label'),
            self::SendOriginal => __('translation.auto_message_failure_modes.send_original.label'),
        };
    }

    /**
     * 返回设置页下拉辅助说明。
     */
    public function description(): string
    {
        return match ($this) {
            self::Skip => __('translation.auto_message_failure_modes.skip.description'),
            self::SendOriginal => __('translation.auto_message_failure_modes.send_original.description'),
        };
    }
}
