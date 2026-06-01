<?php

namespace App\Actions\Inbox;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 根据 session 中的最近工作区决定进入哪个收件箱。
 */
class RedirectLastInboxAction
{
    use AsAction;

    public function handle(Request $request): RedirectResponse
    {
        $lastWorkspaceSlug = session('last_workspace_slug');
        if ($lastWorkspaceSlug) {
            if ($workspace = $request->user()->workspaces()->where('slug', $lastWorkspaceSlug)->first()) {
                return redirect()->route('workspace.inbox.show', ['slug' => $workspace->slug]);
            }
        }

        // 找不到最近工作区时，回到用户的第一个工作区。
        if ($firstWorkspace = $request->user()->workspaces()->first()) {
            return redirect()->route('workspace.inbox.show', ['slug' => $firstWorkspace->slug]);
        }

        return redirect()->route('home');
    }

    public function asController(Request $request): RedirectResponse
    {
        return $this->handle($request);
    }
}
