<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 附件上传方式，区分代理上传、预签名上传和分片上传。
 */
enum AttachmentUploadMode: string implements LabeledEnum
{
    case Proxy = 'proxy';
    case PresignedPost = 'presigned_post';
    case PresignedPut = 'presigned_put';
    case Multipart = 'multipart';

    /**
     * 返回上传方式的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Proxy => __('attachments.upload_modes.proxy'),
            self::PresignedPost => __('attachments.upload_modes.presigned_post'),
            self::PresignedPut => __('attachments.upload_modes.presigned_put'),
            self::Multipart => __('attachments.upload_modes.multipart'),
        };
    }
}
