<?php

namespace App\Data\Teammate;

use Spatie\LaravelData\Data;

/**
 * 客服编辑页 props。
 * 由 resources/js/pages/teammates/Edit.vue 消费，用于填充表单和控制可编辑字段。
 */
class ShowEditTeammatePagePropsData extends Data
{
    public function __construct(
        public EditTeammateData $user_form,

        /** @var PermissionGroupData[] */
        public array $permission_groups,
        public bool $can_update_profile,
    ) {}
}
