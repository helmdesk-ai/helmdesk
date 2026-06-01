<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 附件可见性，决定下载地址是否可公开访问。
 */
enum AttachmentVisibility: string implements LabeledEnum
{
    case Public = 'public';
    case Private = 'private';

    /**
     * 返回附件可见性的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Public => __('attachments.visibility.public'),
            self::Private => __('attachments.visibility.private'),
        };
    }
}
