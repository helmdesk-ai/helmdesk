<?php

namespace App\Data\Teammate;

use App\Data\EnumOptionData;
use App\Enums\UserPermission;
use Spatie\LaravelData\Data;

/**
 * 客服权限分组数据。
 * 由客服创建和编辑页面消费，用于按业务模块展示可分配权限。
 */
class PermissionGroupData extends Data
{
    /**
     * @param  EnumOptionData[]  $permissions
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $permissions,
    ) {}

    /**
     * 从权限分组构造页面数据。
     *
     * @param  list<UserPermission>  $permissions
     */
    public static function fromPermissions(string $key, array $permissions): self
    {
        $firstPermission = $permissions[0];

        return new self(
            key: $key,
            label: $firstPermission->groupLabel(),
            permissions: EnumOptionData::fromCases($permissions),
        );
    }

    /**
     * 返回全部权限分组。
     *
     * @return list<self>
     */
    public static function allGroups(): array
    {
        return array_map(
            static fn (string $key, array $permissions): self => self::fromPermissions($key, $permissions),
            array_keys(UserPermission::groupedCases()),
            array_values(UserPermission::groupedCases()),
        );
    }
}
