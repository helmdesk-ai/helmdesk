<?php

namespace App\Actions\Teammate;

use App\Data\Teammate\FormCreateTeammateData;
use App\Data\WorkspaceUserContextData;
use App\Enums\UserOnlineStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 在工作区内新增客服成员。
 */
class CreateTeammateAction
{
    use AsAction;

    public function handle(Workspace $workspace, FormCreateTeammateData $data): User
    {
        $user = User::query()
            ->where('is_super_admin', false)
            ->findOrFail($data->user_id);

        if (filled($workspace->owner_id) && (string) $workspace->owner_id === (string) $user->id) {
            throw ValidationException::withMessages([
                'user_id' => __('workspace.cannot_select_owner'),
            ]);
        }

        if ($workspace->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => __('workspace.user_already_in_workspace'),
            ]);
        }

        $workspace->users()->attach($user->id, [
            'role' => $data->role,
            'nickname' => filled($data->nickname) ? $data->nickname : null,
            'online_status' => UserOnlineStatus::Online,
        ]);

        return $user;
    }

    public function asController(Request $request)
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $currentWorkspace = $ctx->workspace();
        $data = FormCreateTeammateData::from($request);
        $this->handle($currentWorkspace, $data);

        return redirect()->route('workspace.manage.teammates.index', ['slug' => $ctx->workspaceSlug()]);
    }
}
