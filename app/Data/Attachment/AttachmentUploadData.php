<?php

namespace App\Data\Attachment;

use App\Enums\AttachmentUploadMode;
use App\Models\AttachmentUpload;
use Spatie\LaravelData\Data;

/**
 * 附件上传意图响应数据，告诉前端使用哪种上传模式。
 */
class AttachmentUploadData extends Data
{
    /**
     * 承载上传意图 ID、模式和过期时间。
     */
    public function __construct(
        public string $id,
        public AttachmentUploadMode $mode,
        public string $expires_at,
    ) {}

    /**
     * 从上传意图模型创建前端上传指令数据。
     */
    public static function fromModel(AttachmentUpload $upload): self
    {
        return new self(
            id: (string) $upload->id,
            mode: $upload->mode,
            expires_at: $upload->expires_at->toIso8601String(),
        );
    }
}
