<?php

namespace App\Services\Storage;

use App\Actions\Attachment\ValidateAttachmentUploadAction;
use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use App\Enums\AttachmentVisibility;
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

        $mode = $upload->mode;

        if ($mode === AttachmentUploadMode::Multipart) {
            return $this->initiateMultipart($upload);
        }

        if ($mode === AttachmentUploadMode::PresignedPut) {
            return $this->signPut($upload);
        }

        return $this->signPost($upload);
    }

    /**
     * 为多个分片号生成上传地址。
     *
     * @param  list<int>  $partNumbers
     * @return array<string, mixed>
     */
    public function signParts(AttachmentUpload $upload, array $partNumbers): array
    {
        $upload->loadMissing('storageProfile');
        $client = $this->s3ClientFactory->make($upload->storageProfile);
        $parts = [];

        foreach ($partNumbers as $partNumber) {
            $command = $client->getCommand('UploadPart', [
                'Bucket' => $upload->storageProfile->bucket,
                'Key' => $upload->object_key,
                'UploadId' => $upload->upload_id,
                'PartNumber' => $partNumber,
            ]);
            $request = $client->createPresignedRequest($command, $upload->expires_at);
            $parts[] = [
                'part_number' => $partNumber,
                'url' => (string) $request->getUri(),
                'method' => 'PUT',
            ];
        }

        return ['parts' => $parts];
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
     * 生成 PUT 直传地址并标记上传为进行中。
     *
     * @return array<string, mixed>
     */
    private function signPut(AttachmentUpload $upload): array
    {
        $client = $this->s3ClientFactory->make($upload->storageProfile);
        $commandPayload = [
            'Bucket' => $upload->storageProfile->bucket,
            'Key' => $upload->object_key,
            'ContentType' => $upload->expected_mime_type,
        ];

        if ($upload->attachment->visibility === AttachmentVisibility::Public) {
            $commandPayload['ACL'] = 'public-read';
        }

        if (filled($upload->expected_checksum_sha256)) {
            $commandPayload['Metadata'] = ['checksum-sha256' => $upload->expected_checksum_sha256];
        }

        $command = $client->getCommand('PutObject', $commandPayload);
        $request = $client->createPresignedRequest($command, $upload->expires_at);
        $headers = [
            'Content-Type' => $upload->expected_mime_type,
        ];

        if (filled($upload->expected_checksum_sha256)) {
            $headers['x-amz-meta-checksum-sha256'] = $upload->expected_checksum_sha256;
        }

        $upload->update([
            'mode' => AttachmentUploadMode::PresignedPut,
            'status' => AttachmentUploadStatus::Uploading,
        ]);

        return [
            'upload' => $this->uploadPayload($upload->fresh()),
            'direct' => [
                'url' => (string) $request->getUri(),
                'method' => 'PUT',
                'headers' => $headers,
            ],
        ];
    }

    /**
     * 初始化分片上传并返回分片参数。
     *
     * @return array<string, mixed>
     */
    private function initiateMultipart(AttachmentUpload $upload): array
    {
        $client = $this->s3ClientFactory->make($upload->storageProfile);
        $payload = [
            'Bucket' => $upload->storageProfile->bucket,
            'Key' => $upload->object_key,
            'ContentType' => $upload->expected_mime_type,
        ];

        if (filled($upload->expected_checksum_sha256)) {
            $payload['Metadata'] = ['checksum-sha256' => $upload->expected_checksum_sha256];
        }

        $result = $client->createMultipartUpload($payload);

        $upload->update([
            'status' => AttachmentUploadStatus::Uploading,
            'upload_id' => (string) $result->get('UploadId'),
            'part_size' => ValidateAttachmentUploadAction::PART_SIZE,
        ]);

        return [
            'upload' => $this->uploadPayload($upload->fresh()),
            'direct' => [
                'upload_id' => (string) $result->get('UploadId'),
                'part_size' => ValidateAttachmentUploadAction::PART_SIZE,
            ],
        ];
    }

    /**
     * 组装前端需要的上传意图状态。
     *
     * @return array{id: string, mode: string, expires_at: string, part_size?: int|null}
     */
    private function uploadPayload(AttachmentUpload $upload): array
    {
        return [
            'id' => (string) $upload->id,
            'mode' => $upload->mode->value,
            'expires_at' => $upload->expires_at->toIso8601String(),
            'part_size' => $upload->part_size,
        ];
    }
}
