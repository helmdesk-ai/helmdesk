<?php

namespace App\Actions\Teammate;

use App\Data\WorkspaceUserContextData;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 从工作区移除客服成员。
 */
class RemoveTeammateAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $id): void
    {
        $targetUser = $workspace->users()->whereKey($id)->firstOrFail();

        Gate::authorize('workspace-users.removeMember', [$workspace, $targetUser]);

        if (filled($workspace->owner_id) && (string) $workspace->owner_id === (string) $targetUser->id) {
            throw ValidationException::withMessages([
                'user_id' => __('workspace.cannot_remove_owner'),
            ]);
        }

        $workspace->users()->detach($targetUser->id);
    }

    public function asController(Request $request, string $slug, string $id)
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $this->handle($currentWorkspace, $id);

        return back();
    }
}
