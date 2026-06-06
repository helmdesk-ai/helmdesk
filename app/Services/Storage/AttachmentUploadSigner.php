<?php

namespace App\Services\Storage;

use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use App\Enums\StorageDriver;
use App\Models\AttachmentUpload;
use Aws\S3\PostObjectV4;

/**
 * 为附件上传意图生成对象存储直传参数。
 */
class AttachmentUploadSigner
{
    /**
     * 注入对象存储客户端工厂。
     */
    public function __construct(
        private readonly S3ClientFactory $s3ClientFactory,
    ) {}

    /**
     * 根据上传模式生成浏览器上传参数。
     *
     * @return array<string, mixed>
     */
    public function sign(AttachmentUpload $upload): array
    {
        $upload->loadMissing(['attachment', 'storageProfile']);

        $driver = $upload->storageProfile->driver;

        if ($driver === StorageDriver::Local) {
            $upload->update(['mode' => AttachmentUploadMode::Proxy]);

            return [
                'upload' => $this->uploadPayload($upload->fresh()),
                'direct' => null,
            ];
        }

        return $this->signPost($upload);
    }

    /**
     * 生成表单直传参数并标记上传为进行中。
     *
     * @return array<string, mixed>
     */
    private function signPost(AttachmentUpload $upload): array
    {
        $client = $this->s3ClientFactory->make($upload->storageProfile);
        $inputs = [
            'key' => $upload->object_key,
            'Content-Type' => $upload->expected_mime_type,
        ];
        $conditions = [
            ['bucket' => $upload->storageProfile->bucket],
            ['key' => $upload->object_key],
            ['Content-Type' => $upload->expected_mime_type],
        ];

        if (filled($upload->expected_checksum_sha256)) {
            $inputs['x-amz-meta-checksum-sha256'] = $upload->expected_checksum_sha256;
            $conditions[] = ['x-amz-meta-checksum-sha256' => $upload->expected_checksum_sha256];
        }

        $post = new PostObjectV4(
            $client,
            (string) $upload->storageProfile->bucket,
            $inputs,
            $conditions,
            $upload->expires_at,
        );

        $upload->update([
            'mode' => AttachmentUploadMode::PresignedPost,
            'status' => AttachmentUploadStatus::Uploading,
        ]);

        return [
            'upload' => $this->uploadPayload($upload->fresh()),
            'direct' => [
                'url' => $post->getFormAttributes()['action'],
                'method' => 'POST',
                'fields' => $post->getFormInputs(),
            ],
        ];
    }

    /**
     * 组装前端需要的上传意图状态。
     *
     * @return array{id: string, mode: string, expires_at: string}
     */
    private function uploadPayload(AttachmentUpload $upload): array
    {
        return [
            'id' => (string) $upload->id,
            'mode' => $upload->mode->value,
            'expires_at' => $upload->expires_at->toIso8601String(),
        ];
    }
}
