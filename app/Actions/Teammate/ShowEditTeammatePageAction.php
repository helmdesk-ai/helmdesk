<?php

namespace App\Actions\Teammate;

use App\Data\EnumOptionData;
use App\Data\Teammate\ShowEditTeammatePagePropsData;
use App\Data\Teammate\TeammateData;
use App\Data\WorkspaceUserContextData;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示客服成员编辑页面。
 */
class ShowEditTeammatePageAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $id): ShowEditTeammatePagePropsData
    {
        $user = $workspace->users()
            ->whereKey($id)
            ->firstOrFail();

        return new ShowEditTeammatePagePropsData(
            user_form: TeammateData::fromModel($user),
            role_options: EnumOptionData::fromCases(WorkspaceRole::assignableCases()),
            can_update_nickname: Gate::allows('workspace-users.updateProfile', [$workspace, $user]),
            can_update_role: Gate::allows('workspace-users.canUpdateRole', [$workspace, $user]),
        );
    }

    public function asController(Request $request, string $slug, string $id)
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $props = $this->handle($currentWorkspace, $id);

        return Inertia::render('teammate/Edit', $props->toArray());
    }
}
