<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 附件上传方式：本地走服务端代理，S3 兼容存储走浏览器预签名表单直传。
 */
enum AttachmentUploadMode: string implements LabeledEnum
{
    case Proxy = 'proxy';
    case PresignedPost = 'presigned_post';

    /**
     * 返回上传方式的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Proxy => __('attachments.upload_modes.proxy'),
            self::PresignedPost => __('attachments.upload_modes.presigned_post'),
        };
    }
}
