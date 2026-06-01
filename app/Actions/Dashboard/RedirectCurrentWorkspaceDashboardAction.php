<?php

namespace App\Actions\Dashboard;

use App\Data\WorkspaceUserContextData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 把当前工作区上下文重定向到对应仪表板。
 */
class RedirectCurrentWorkspaceDashboardAction
{
    use AsAction;

    /**
     * 根据当前工作区上下文生成仪表板重定向响应。
     */
    public function handle(Request $request): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);

        return redirect()->route('workspace.dashboard', $ctx->workspaceSlug());
    }

    /**
     * 处理当前工作区首页入口请求。
     */
    public function asController(Request $request): RedirectResponse
    {
        return $this->handle($request);
    }
}
