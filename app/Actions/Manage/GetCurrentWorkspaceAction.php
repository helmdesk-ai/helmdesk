<?php

namespace App\Actions\Manage;

use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示当前工作区设置页，页面所需数据通过共享 props 下发。
 */
class GetCurrentWorkspaceAction
{
    use AsAction;

    /**
     * 渲染当前工作区设置页。
     */
    public function asController(): Response
    {
        return Inertia::render('currentWorkspace/Index');
    }
}
