<?php

namespace App\Data\CurrentWorkspace;

use App\Data\SimplePaginationData;
use Spatie\LaravelData\Data;

/**
 * 工作区成员数据。
 * 由后端组装后传给 resources/js/pages/currentWorkspace/Index.vue、Create.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class WorkspaceMembersData extends Data
{
    public function __construct(
        /** @var WorkspaceMemberData[] */
        public array $items,
        public SimplePaginationData $pagination,
    ) {}
}
