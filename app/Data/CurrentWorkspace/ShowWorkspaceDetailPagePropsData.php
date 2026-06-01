<?php

namespace App\Data\CurrentWorkspace;

use App\Data\EnumOptionData;
use App\Data\User\UserOptionData;
use Spatie\LaravelData\Data;

/**
 * 工作区详情页面 props。
 * 由对应 Show*Action 返回给 resources/js/pages/currentWorkspace/Index.vue、Create.vue，用于渲染首屏数据、筛选项和页面状态。
 */
class ShowWorkspaceDetailPagePropsData extends Data
{
    public function __construct(
        public WorkspaceDetailData $workspace,
        public WorkspaceMembersData $members,
        /** @var EnumOptionData[] */
        public array $role_options = [],
        /** @var UserOptionData[] */
        public array $available_users = [],
    ) {}
}
