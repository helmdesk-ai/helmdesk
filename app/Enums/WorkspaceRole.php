<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 工作区成员角色，控制工作区内的权限边界。
 */
enum WorkspaceRole: string implements LabeledEnum
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('workspace.roles.owner'),
            self::Admin => __('workspace.roles.admin'),
            self::Operator => __('workspace.roles.operator'),
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
