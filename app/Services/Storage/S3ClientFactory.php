<?php

namespace App\Services\Storage;

use App\Enums\StorageDriver;
use App\Models\StorageProfile;
use Aws\S3\S3Client;

/**
 * 按存储配置创建 S3 兼容客户端。
 */
class S3ClientFactory
{
    /**
     * 根据 S3 兼容存储配置创建 SDK 客户端。
     */
    public function make(StorageProfile $profile): S3Client
    {
        if ($profile->driver !== StorageDriver::S3) {
            throw new \InvalidArgumentException('S3 client can only be created for S3 storage profiles.');
        }

        return new S3Client($profile->s3ClientConfig());
    }
}
