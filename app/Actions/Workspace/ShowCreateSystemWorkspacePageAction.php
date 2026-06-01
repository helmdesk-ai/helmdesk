<?php

namespace App\Actions\Workspace;

use App\Data\User\UserOptionData;
use App\Data\Workspace\ShowCreateWorkspacePagePropsData;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示超级管理后台创建工作区页面并提供可选拥有者列表。
 */
class ShowCreateSystemWorkspacePageAction
{
    use AsAction;

    /**
     * 装配创建工作区页面所需的可选拥有者下拉选项。
     */
    public function handle(): ShowCreateWorkspacePagePropsData
    {
        $owners = User::query()
            ->where('is_super_admin', false)
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => UserOptionData::fromModel($u))
            ->all();

        return new ShowCreateWorkspacePagePropsData(
            owner_options: $owners,
        );
    }

    /**
     * 渲染超级管理后台创建工作区页面。
     */
    public function asController(): Response
    {
        return Inertia::render('admin/workspace/Create', $this->handle()->toArray());
    }
}
