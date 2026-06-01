<?php

namespace App\Actions\Manage;

use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示管理中心的新建工作区页面。
 */
class ShowCreateWorkspacePageAction
{
    use AsAction;

    /**
     * 渲染新建工作区表单页面，无需后端数据。
     */
    public function asController(): Response
    {
        return Inertia::render('currentWorkspace/Create');
    }
}
