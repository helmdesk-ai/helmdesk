<?php

namespace App\Actions\Teammate;

use App\Data\Teammate\FormUpdateTeammateData;
use App\Data\WorkspaceUserContextData;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新工作区客服成员资料和角色。
 */
class UpdateTeammateAction
{
    use AsAction;

    public function handle(Workspace $workspace, $userId, FormUpdateTeammateData $data): void
    {
        $targetUser = $workspace->users()->whereKey($userId)->firstOrFail();

        $currentNickname = filled($targetUser->pivot?->nickname) ? (string) $targetUser->pivot->nickname : null;
        $nextNickname = filled($data->nickname) ? $data->nickname : null;

        if ($nextNickname !== $currentNickname) {
            Gate::authorize('workspace-users.updateProfile', [$workspace, $targetUser]);
        }

        if ($data->role->value !== (string) ($targetUser->pivot?->role ?? '')) {
            Gate::authorize('workspace-users.updateRole', [$workspace, $targetUser, $data->role]);
        }

        $targetUser->pivot->update([
            'nickname' => $nextNickname,
            'role' => $data->role,
        ]);
    }

    public function asController(Request $request, string $slug, string $id)
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $data = FormUpdateTeammateData::from($request);
        $this->handle($workspace, $id, $data);

        return redirect()->route('workspace.manage.teammates.index', ['slug' => $ctx->workspaceSlug()]);
    }
}
