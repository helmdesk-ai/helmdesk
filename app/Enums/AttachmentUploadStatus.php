<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 附件上传意图状态，用于清理过期或中止的上传流程。
 */
enum AttachmentUploadStatus: string implements LabeledEnum
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Aborted = 'aborted';
    case Expired = 'expired';
    case Failed = 'failed';

    /**
     * 返回上传意图状态的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('attachments.upload_status.pending'),
            self::Uploading => __('attachments.upload_status.uploading'),
            self::Completed => __('attachments.upload_status.completed'),
            self::Aborted => __('attachments.upload_status.aborted'),
            self::Expired => __('attachments.upload_status.expired'),
            self::Failed => __('attachments.upload_status.failed'),
        };
    }
}
