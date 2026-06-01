<?php

namespace App\Actions\Teammate;

use App\Data\EnumOptionData;
use App\Data\Teammate\ShowListTeammatePagePropsData;
use App\Data\WorkspaceUserContextData;
use App\Enums\UserOnlineStatus;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询工作区客服成员列表。
 */
class ShowTeammateListAction
{
    use AsAction;

    public function handle(Workspace $workspace): ShowListTeammatePagePropsData
    {
        $users = $workspace->users()
            ->orderBy('users.id', 'asc')
            ->get();

        $userList = $users->map(fn ($u) => WorkspaceUserContextData::fromModels($workspace, $u)
            ->withShowRemoveButton(Gate::allows('workspace-users.removeMember', [$workspace, $u]))
        )->all();

        return new ShowListTeammatePagePropsData(
            user_list: $userList,
            online_status_options: EnumOptionData::fromCases(UserOnlineStatus::cases()),
        );
    }

    public function asController(Request $request)
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $props = $this->handle($currentWorkspace);

        return Inertia::render('teammate/List', $props->toArray());
    }
}
