<?php

namespace App\Services\Storage;

use App\Enums\StorageDriver;
use App\Models\StorageProfile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * 按存储配置创建临时磁盘实例。
 */
class StorageProfileDisk
{
    /**
     * 按存储配置创建临时文件系统实例。
     */
    public static function build(StorageProfile $profile): FilesystemAdapter
    {
        if ($profile->driver === StorageDriver::Local) {
            return Storage::disk('local');
        }

        return Storage::build($profile->s3FilesystemConfig());
    }
}
