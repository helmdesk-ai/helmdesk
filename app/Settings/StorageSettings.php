<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * 对象存储开关和当前配置。
 */
class StorageSettings extends Settings
{
    /**
     * 对象存储总开关；关闭时上传仍走本地默认存储。
     */
    public bool $enabled = false;

    /**
     * 当前启用的存储配置 ID；上传文件时据此选择磁盘。
     */
    public ?string $current_profile_id;

    public static function group(): string
    {
        return 'storage';
    }
}
