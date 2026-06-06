<?php

namespace App\Actions\Attachment;

use App\Enums\AttachmentPurpose;
use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 将已完成上传的附件绑定到业务模型。
 */
class AttachUploadedAttachmentsAction
{
    use AsAction;

    /**
     * 校验附件可绑定后关联到指定业务模型。
     *
     * @param  list<AttachmentPurpose>  $allowedPurposes
     */
    public function handle(
        Model $attachable,
        string $attachmentId,
        ?string $scope = null,
        ?User $actor = null,
        ?string $sessionToken = null,
        array $allowedPurposes = [],
    ): Attachment {
        $attachment = $this->assertCanAttach($attachable, $attachmentId, $scope, $actor, $sessionToken, $allowedPurposes);

        $attachment->update([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'status' => AttachmentStatus::Attached,
            'attached_at' => now(),
            'expires_at' => null,
        ]);

        return $attachment->fresh();
    }

    /**
     * 返回可绑定附件，供批量绑定前复用同一套校验。
     *
     * @param  list<AttachmentPurpose>  $allowedPurposes
     */
    public function assertCanAttach(
        Model $attachable,
        string $attachmentId,
        ?string $scope = null,
        ?User $actor = null,
        ?string $sessionToken = null,
        array $allowedPurposes = [],
    ): Attachment {
        $attachment = Attachment::query()->find($attachmentId);

        if (! $attachment) {
            throw ValidationException::withMessages(['attachment_ids' => __('attachments.errors.not_uploaded')]);
        }

        $this->assertAttachmentCanAttach($attachment, $attachable, $scope, $actor, $sessionToken, $allowedPurposes);

        return $attachment;
    }

    /**
     * 校验附件归属、状态、用途和上传者是否允许绑定。
     *
     * @param  list<AttachmentPurpose>  $allowedPurposes
     */
    private function assertAttachmentCanAttach(
        Attachment $attachment,
        Model $attachable,
        ?string $scope,
        ?User $actor,
        ?string $sessionToken,
        array $allowedPurposes,
    ): void {
        $isSameAttachable = $attachment->attachable_id !== null
            && (string) $attachment->attachable_id === (string) $attachable->getKey()
            && $attachment->attachable_type === $attachable->getMorphClass();

        if (! $isSameAttachable && $attachment->status !== AttachmentStatus::Uploaded) {
            throw ValidationException::withMessages(['attachment_ids' => __('attachments.errors.not_uploaded')]);
        }

        if ($attachment->attachable_id !== null && ! $isSameAttachable) {
            throw ValidationException::withMessages(['attachment_ids' => __('attachments.errors.already_attached')]);
        }

        if ($allowedPurposes !== [] && ! in_array($attachment->purpose, $allowedPurposes, true)) {
            throw ValidationException::withMessages(['attachment_ids' => __('attachments.errors.invalid_purpose')]);
        }

        if ($actor && (string) $attachment->uploaded_by_user_id !== (string) $actor->id) {
            throw ValidationException::withMessages(['attachment_ids' => __('auth.unauthorized')]);
        }

        if ($sessionToken) {
            $hash = hash('sha256', $sessionToken);
            $matchesSession = AttachmentUpload::query()
                ->where('attachment_id', $attachment->id)
                ->where('session_token_hash', $hash)
                ->exists();

            if (! $matchesSession) {
                throw ValidationException::withMessages(['attachment_ids' => __('auth.unauthorized')]);
            }
        }
    }
}
