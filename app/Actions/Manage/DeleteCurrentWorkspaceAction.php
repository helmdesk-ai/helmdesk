<?php

namespace App\Actions\Manage;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除当前工作区。
 */
class DeleteCurrentWorkspaceAction
{
    use AsAction;

    public function handle(Workspace $workspace)
    {
        if (! empty($workspace->owner_id)) {
            throw new BusinessException(__('workspace.delete_default_workspace'));
        }

        $workspace->delete();
    }

    public function asController(Request $request)
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $this->handle($currentWorkspace);

        $defaultWorkspace = GetDefaultWorkspaceAction::run($request->user());

        return redirect(route('workspace.manage.workspaces.current.show', $defaultWorkspace->slug));
    }
}
