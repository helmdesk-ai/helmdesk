<?php

namespace App\Actions\Attachment;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentUploadStatus;
use App\Models\AttachmentUpload;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 校验附件上传完成时的状态转换和对象元数据。
 */
class ValidateCompletedAttachmentUploadAction
{
    use AsAction;

    /**
     * 校验上传意图状态和对象实际信息。
     *
     * @param  array{byte_size: int, mime_type: string|null, checksum_sha256?: string|null}  $object
     */
    public function handle(AttachmentUpload $upload, array $object, ?string $checksumSha256): void
    {
        $this->assertCompletable($upload);
        $this->assertObjectMatches($upload, $object, $checksumSha256);
    }

    /**
     * 确认上传意图和附件仍可完成。
     */
    public function assertCompletable(AttachmentUpload $upload): void
    {
        if ($upload->status === AttachmentUploadStatus::Completed) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.upload_already_completed')]);
        }

        if (! in_array($upload->status, [AttachmentUploadStatus::Pending, AttachmentUploadStatus::Uploading], true)) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.invalid_upload_state')]);
        }

        if ($upload->attachment->attachable_id !== null || $upload->attachment->status === AttachmentStatus::Attached) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.already_attached')]);
        }

        if ($upload->attachment->status !== AttachmentStatus::Pending) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.invalid_upload_state')]);
        }
    }

    /**
     * 校验对象实际信息是否符合上传意图声明。
     *
     * @param  array{byte_size: int, mime_type: string|null, checksum_sha256?: string|null}  $object
     */
    private function assertObjectMatches(AttachmentUpload $upload, array $object, ?string $checksumSha256): void
    {
        if ($object['byte_size'] !== $upload->expected_byte_size) {
            throw ValidationException::withMessages(['byte_size' => __('attachments.errors.object_size_mismatch')]);
        }

        $expectedChecksum = $checksumSha256 ?: $upload->expected_checksum_sha256;
        if ($expectedChecksum && ($object['checksum_sha256'] ?? null) !== $expectedChecksum) {
            throw ValidationException::withMessages(['checksum_sha256' => __('attachments.errors.object_checksum_mismatch')]);
        }
    }
}
