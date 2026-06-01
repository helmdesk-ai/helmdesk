<?php

namespace App\Actions\Attachment;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除附件对象、缩略图和附件记录。
 */
class DeleteAttachmentAction
{
    use AsAction;

    /**
     * 删除附件文件、缩略图和数据库记录。
     */
    public function handle(Attachment $attachment): bool
    {
        $metadata = $attachment->metadata ?? [];
        $filesystem = $attachment->filesystem();
        $filesystem->delete($attachment->object_key);

        if (is_string($metadata['thumbnail_key'] ?? null)) {
            $filesystem->delete($metadata['thumbnail_key']);
        }

        $attachment->status = AttachmentStatus::Deleted;
        $attachment->save();

        return (bool) $attachment->delete();
    }
}
