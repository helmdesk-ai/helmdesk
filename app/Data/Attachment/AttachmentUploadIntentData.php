<?php

namespace App\Data\Attachment;

use Spatie\LaravelData\Data;

/**
 * 创建附件上传意图后的完整响应数据，包含附件占位记录和上传参数。
 */
class AttachmentUploadIntentData extends Data
{
    /**
     * 承载附件占位记录、上传意图和可选直传参数。
     */
    public function __construct(
        public UploadedAttachmentData $attachment,
        public AttachmentUploadData $upload,
        public ?AttachmentDirectUploadData $direct,
    ) {}
}
