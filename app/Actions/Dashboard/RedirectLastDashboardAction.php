<?php

namespace App\Actions\Dashboard;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 根据 session 中的最近工作区决定进入哪个仪表板。
 */
class RedirectLastDashboardAction
{
    use AsAction;

    /**
     * 按最近访问工作区生成仪表板重定向响应。
     */
    public function handle(Request $request): RedirectResponse
    {
        $lastWorkspaceSlug = session('last_workspace_slug');
        if ($lastWorkspaceSlug) {
            if ($workspace = $request->user()->workspaces()->where('slug', $lastWorkspaceSlug)->first()) {
                return redirect()->route('workspace.dashboard', ['slug' => $workspace->slug]);
            }
        }

        if ($firstWorkspace = $request->user()->workspaces()->first()) {
            return redirect()->route('workspace.dashboard', ['slug' => $firstWorkspace->slug]);
        }

        return redirect()->route('home');
    }

    /**
     * 处理默认仪表板入口请求。
     */
    public function asController(Request $request): RedirectResponse
    {
        return $this->handle($request);
    }
}
