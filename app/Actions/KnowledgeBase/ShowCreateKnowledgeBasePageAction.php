<?php

namespace App\Actions\KnowledgeBase;

use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 打开创建知识库页面。
 * 当前创建表单不依赖任何后端首屏数据，直接渲染创建页。
 */
class ShowCreateKnowledgeBasePageAction
{
    use AsAction;

    /**
     * 返回创建知识库页面。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::KnowledgeBasesCreate);

        return Inertia::render('knowledgeBase/Create');
    }
}
