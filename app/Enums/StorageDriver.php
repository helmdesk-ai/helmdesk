<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 存储驱动类型，区分本地私有存储和 S3 兼容对象存储。
 */
enum StorageDriver: string implements LabeledEnum
{
    case Local = 'local';
    case S3 = 's3';

    /**
     * 返回存储驱动的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Local => __('storage_settings.drivers.local'),
            self::S3 => __('storage_settings.drivers.s3'),
        };
    }
}
