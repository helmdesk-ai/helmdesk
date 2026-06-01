<?php

namespace App\Data\Teammate;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 客服成员页面 props。
 * 由对应 Show*Action 返回给 resources/js/pages/teammate/List.vue、Create.vue、Edit.vue，用于渲染首屏数据、筛选项和页面状态。
 */
class ShowEditTeammatePagePropsData extends Data
{
    public function __construct(
        public TeammateData $user_form,
        /** @var EnumOptionData[] */
        public array $role_options,
        public bool $can_update_nickname,
        public bool $can_update_role,
    ) {}
}
