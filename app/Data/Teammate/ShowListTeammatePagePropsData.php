<?php

namespace App\Data\Teammate;

use App\Data\EnumOptionData;
use App\Data\WorkspaceUserContextData;
use Spatie\LaravelData\Data;

/**
 * 客服成员页面 props。
 * 由对应 Show*Action 返回给 resources/js/pages/teammate/List.vue、Create.vue、Edit.vue，用于渲染首屏数据、筛选项和页面状态。
 */
class ShowListTeammatePagePropsData extends Data
{
    public function __construct(
        /** @var WorkspaceUserContextData[] */
        public array $user_list,

        /** @var EnumOptionData[] */
        public array $online_status_options,
    ) {}
}
