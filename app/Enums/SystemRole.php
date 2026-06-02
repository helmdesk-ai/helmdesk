<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 系统成员角色，控制系统内的权限边界。
 */
enum SystemRole: string implements LabeledEnum
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('admin.roles.owner'),
            self::Admin => __('admin.roles.admin'),
            self::Operator => __('admin.roles.operator'),
        };
    }

    /**
     * @return array<int, self>
     */
    public static function assignableCases(): array
    {
        return [
            self::Admin,
            self::Operator,
        ];
    }
}
