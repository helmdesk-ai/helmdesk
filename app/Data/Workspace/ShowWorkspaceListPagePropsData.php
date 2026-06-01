<?php

namespace App\Data\Workspace;

use App\Data\SimplePaginationData;
use Spatie\LaravelData\Data;

/**
 * 工作区页面 props。
 * 由对应 Show*Action 返回给 resources/js/pages/admin/workspace/*，用于渲染首屏数据、筛选项和页面状态。
 */
class ShowWorkspaceListPagePropsData extends Data
{
    public function __construct(
        /** @var WorkspaceData[] */
        public array $workspace_list,
        public SimplePaginationData $workspace_list_pagination,
    ) {}
}
