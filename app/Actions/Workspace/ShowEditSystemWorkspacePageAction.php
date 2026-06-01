<?php

namespace App\Actions\Workspace;

use App\Data\User\UserOptionData;
use App\Data\Workspace\ShowEditWorkspacePagePropsData;
use App\Data\Workspace\WorkspaceFormData;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示超级管理后台编辑工作区页面，并下发当前资料和可选拥有者列表。
 */
class ShowEditSystemWorkspacePageAction
{
    use AsAction;

    /**
     * 装配编辑工作区页面所需的当前资料和拥有者下拉选项。
     */
    public function handle(string $id): ShowEditWorkspacePagePropsData
    {
        $workspace = Workspace::query()->findOrFail($id);

        $owners = User::query()
            ->where('is_super_admin', false)
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => UserOptionData::fromModel($u))
            ->all();

        return new ShowEditWorkspacePagePropsData(
            workspace: WorkspaceFormData::fromModel($workspace),
            owner_options: $owners,
        );
    }

    /**
     * 渲染超级管理后台编辑工作区页面。
     */
    public function asController(string $id): Response
    {
        return Inertia::render('admin/workspace/Edit', $this->handle($id)->toArray());
    }
}
