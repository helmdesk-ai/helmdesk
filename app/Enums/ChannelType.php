<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 渠道类型，用于区分网站、Telegram 等访客接入来源。
 */
enum ChannelType: string implements LabeledEnum
{
    case Web = 'web';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Web => __('channel.types.web'),
            self::Telegram => __('channel.types.telegram'),
        };
    }
}
