<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 联系人身份标识类型，如邮箱、手机号、会话 token。
 */
enum IdentityType: string implements LabeledEnum
{
    case Session = 'session';
    case Email = 'email';
    case Phone = 'phone';
    case ExternalId = 'external_id';

    public function label(): string
    {
        return match ($this) {
            self::Session => __('contact.identity_types.session'),
            self::Email => __('contact.identity_types.email'),
            self::Phone => __('contact.identity_types.phone'),
            self::ExternalId => __('contact.identity_types.external_id'),
        };
    }

    public function requiresNamespace(): bool
    {
        return match ($this) {
            self::ExternalId => true,
            default => false,
        };
    }

    public function supportsManualManagement(): bool
    {
        return match ($this) {
            self::Email, self::Phone => true,
            default => false,
        };
    }
}
