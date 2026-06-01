<?php

namespace App\Actions\Workspace;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 从工作区成员列表中移除用户。
 */
class DeleteWorkspaceMemberAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $userId): void
    {
        if (filled($workspace->owner_id) && (string) $workspace->owner_id === (string) $userId) {
            throw ValidationException::withMessages([
                'user_id' => __('workspace.cannot_remove_owner'),
            ]);
        }

        $workspace->users()->detach($userId);
    }

    public function asController(Request $request, string $id, string $userId): RedirectResponse
    {
        $workspace = Workspace::query()->findOrFail($id);

        $this->handle($workspace, $userId);

        return redirect()->route('admin.workspaces.show', ['id' => $workspace->id]);
    }
}
