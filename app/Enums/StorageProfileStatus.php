<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 存储配置启用状态，用于筛选当前可用的上传目标。
 */
enum StorageProfileStatus: string implements LabeledEnum
{
    case Active = 'active';
    case Disabled = 'disabled';

    /**
     * 返回存储配置状态的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => __('storage_settings.status.active'),
            self::Disabled => __('storage_settings.status.disabled'),
        };
    }
}
