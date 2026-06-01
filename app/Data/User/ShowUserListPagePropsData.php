<?php

namespace App\Data\User;

use App\Data\SimplePaginationData;
use Spatie\LaravelData\Data;

/**
 * 用户页面 props。
 * 由对应 Show*Action 返回给 resources/js/pages/admin/user/* 和 pages/settings/*，用于渲染首屏数据、筛选项和页面状态。
 */
class ShowUserListPagePropsData extends Data
{
    public function __construct(
        /** @var ListUserItemData[] */
        public array $user_list,
        public SimplePaginationData $user_list_pagination,
    ) {}
}
