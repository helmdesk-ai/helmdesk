<?php

namespace App\Actions\KnowledgeBase;

use App\Data\WorkspaceUserContextData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 打开创建知识库页面。
 * 当前创建表单不依赖任何后端首屏数据，因此不再下发 PageProps。
 */
class ShowCreateKnowledgeBasePageAction
{
    use AsAction;

    /**
     * 返回创建知识库页面。
     */
    public function asController(Request $request): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        return Inertia::render('knowledgeBase/Create');
    }
}
