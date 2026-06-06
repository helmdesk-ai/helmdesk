<?php

namespace App\Services\Storage;

use App\Actions\Attachment\ValidateAttachmentUploadAction;
use App\Actions\Attachment\ValidateCompletedAttachmentUploadAction;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentUploadStatus;
use App\Enums\StorageDriver;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 完成附件上传意图，校验对象元数据并更新附件状态。
 */
class AttachmentUploadCompleter
{
    /**
     * 注入对象存储客户端工厂、上传完成校验器和图片缩略图生成器。
     */
    public function __construct(
        private readonly S3ClientFactory $s3ClientFactory,
        private readonly ValidateCompletedAttachmentUploadAction $completedUploadValidator,
        private readonly AttachmentThumbnailer $thumbnailer,
    ) {}

    /**
     * 校验并完成上传意图，更新附件状态和对象元数据。
     */
    public function complete(AttachmentUpload $upload, ?string $checksumSha256 = null): Attachment
    {
        $upload->loadMissing(['attachment', 'storageProfile']);
        $this->completedUploadValidator->assertCompletable($upload);

        if ($upload->expires_at->isPast()) {
            $this->markExpired($upload);
            throw ValidationException::withMessages(['upload' => __('attachments.errors.upload_expired')]);
        }

        $object = $this->inspectObject($upload);
        $this->completedUploadValidator->handle($upload, $object, $checksumSha256);

        $attachment = DB::transaction(function () use ($upload, $object): Attachment {
            /** @var Attachment $attachment */
            $attachment = $upload->attachment()->lockForUpdate()->firstOrFail();
            $upload->refresh();
            $upload->setRelation('attachment', $attachment);
            $this->completedUploadValidator->assertCompletable($upload);

            $attachment->update([
                'status' => AttachmentStatus::Uploaded,
                'etag' => $object['etag'] ?? $attachment->etag,
                'checksum_sha256' => $object['checksum_sha256'] ?? $attachment->checksum_sha256,
                'metadata' => array_merge($attachment->metadata ?? [], $object['metadata'] ?? []),
                'uploaded_at' => now(),
                'expires_at' => now()->addDay(),
            ]);

            $upload->update([
                'status' => AttachmentUploadStatus::Completed,
                'completed_at' => now(),
            ]);

            return $attachment->fresh();
        });

        try {
            $this->thumbnailer->generate($attachment);
        } catch (Throwable $e) {
            Log::warning('attachment thumbnail generation failed', [
                'attachment_id' => $attachment->id,
                'mime_type' => $attachment->mime_type,
                'error' => $e->getMessage(),
            ]);
        }

        return $attachment->fresh() ?? $attachment;
    }

    /**
     * 读取已上传对象的大小、类型、校验和与图片元数据。
     *
     * @return array{byte_size: int, mime_type: string|null, etag?: string|null, checksum_sha256?: string|null, metadata?: array<string, mixed>}
     */
    private function inspectObject(AttachmentUpload $upload): array
    {
        $driver = $upload->storageProfile->driver;

        if ($driver === StorageDriver::Local) {
            $disk = Storage::disk('local');

            if (! $disk->exists($upload->object_key)) {
                throw ValidationException::withMessages(['upload' => __('attachments.errors.object_missing')]);
            }

            $checksum = $upload->expected_checksum_sha256
                ? hash_file('sha256', $disk->path($upload->object_key))
                : null;

            $mimeType = $disk->mimeType($upload->object_key) ?: $upload->expected_mime_type;

            return [
                'byte_size' => $disk->size($upload->object_key),
                'mime_type' => $mimeType,
                'checksum_sha256' => $checksum,
                'metadata' => $this->isImageMimeType($mimeType)
                    ? $this->imageMetadata($disk->path($upload->object_key))
                    : [],
            ];
        }

        $client = $this->s3ClientFactory->make($upload->storageProfile);
        $result = $client->headObject([
            'Bucket' => $upload->storageProfile->bucket,
            'Key' => $upload->object_key,
        ]);

        $metadata = $result->get('Metadata') ?? [];

        return [
            'byte_size' => (int) $result->get('ContentLength'),
            'mime_type' => $result->get('ContentType') ? ValidateAttachmentUploadAction::normalizeMimeType((string) $result->get('ContentType')) : null,
            'etag' => $result->get('ETag') ? trim((string) $result->get('ETag'), '"') : null,
            'checksum_sha256' => $metadata['checksum-sha256'] ?? null,
            'metadata' => [],
        ];
    }

    /**
     * 从本地图片文件读取宽高元数据。
     *
     * @return array<string, int>
     */
    private function imageMetadata(string $path): array
    {
        if (! is_file($path)) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.object_missing')]);
        }

        $size = getimagesize($path);
        if ($size === false) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.invalid_image_metadata')]);
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    private function isImageMimeType(?string $mimeType): bool
    {
        return is_string($mimeType) && str_starts_with($mimeType, 'image/');
    }

    /**
     * 将上传意图和附件标记为过期。
     */
    private function markExpired(AttachmentUpload $upload): void
    {
        $upload->update(['status' => AttachmentUploadStatus::Expired]);
        $upload->attachment->update(['status' => AttachmentStatus::Expired]);
    }
}
