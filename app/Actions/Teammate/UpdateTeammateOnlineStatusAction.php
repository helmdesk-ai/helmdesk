<?php

namespace App\Actions\Teammate;

use App\Data\Teammate\FormUpdateTeammateOnlineStatusData;
use App\Data\WorkspaceUserContextData;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新当前成员接待状态。
 */
class UpdateTeammateOnlineStatusAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $id, FormUpdateTeammateOnlineStatusData $data): void
    {
        $user = $workspace->users()->whereKey($id)->firstOrFail();

        $user->pivot->update([
            'online_status' => $data->online_status,
        ]);
    }

    public function asController(Request $request, string $slug, string $id)
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $data = FormUpdateTeammateOnlineStatusData::from($request);
        $this->handle($currentWorkspace, $id, $data);

        return back();
    }
}
