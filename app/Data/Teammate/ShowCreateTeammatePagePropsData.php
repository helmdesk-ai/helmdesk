<?php

namespace App\Data\Teammate;

use Spatie\LaravelData\Data;

/**
 * 客服创建页 props。
 * 由 resources/js/pages/teammates/Create.vue 消费，用于渲染可分配权限。
 */
class ShowCreateTeammatePagePropsData extends Data
{
    public function __construct(
        /** @var PermissionGroupData[] */
        public array $permission_groups,
    ) {}
}
