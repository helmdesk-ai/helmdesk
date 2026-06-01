<?php

namespace App\Actions\User;

use App\Data\Teammate\FormUpdateTeammateOnlineStatusData;
use App\Data\WorkspaceUserContextData;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新当前用户在线状态。
 */
class UpdateMyOnlineStatusAction
{
    use AsAction;

    /**
     * 保存当前用户在工作区内的在线状态。
     */
    public function handle(Workspace $workspace, string $userId, FormUpdateTeammateOnlineStatusData $data): void
    {
        $user = $workspace->users()->whereKey($userId)->firstOrFail();

        $user->pivot->update([
            'online_status' => $data->online_status,
        ]);
    }

    /**
     * 接收当前用户在线状态更新请求。
     */
    public function asController(Request $request, string $slug)
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $data = FormUpdateTeammateOnlineStatusData::from($request);
        $this->handle($currentWorkspace, $request->user()->id, $data);

        return back();
    }
}
