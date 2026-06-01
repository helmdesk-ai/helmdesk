<?php

namespace App\Actions\Teammate;

use App\Data\EnumOptionData;
use App\Data\Teammate\ShowCreateTeammatePagePropsData;
use App\Data\User\UserOptionData;
use App\Data\WorkspaceUserContextData;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示新增客服成员页面。
 */
class ShowCreateTeammatePageAction
{
    use AsAction;

    public function handle(Workspace $workspace): ShowCreateTeammatePagePropsData
    {
        $memberIds = $workspace->users()
            ->pluck('users.id')
            ->map(static fn ($v) => (string) $v)
            ->all();

        $availableUsers = User::query()
            ->where('is_super_admin', false)
            ->when(filled($workspace->owner_id), fn ($q) => $q->whereKeyNot((string) $workspace->owner_id))
            ->when(! empty($memberIds), fn ($q) => $q->whereKeyNot($memberIds))
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => UserOptionData::fromModel($u))
            ->all();

        return new ShowCreateTeammatePagePropsData(
            role_options: EnumOptionData::fromCases(WorkspaceRole::assignableCases()),
            available_users: $availableUsers,
        );
    }

    public function asController(Request $request)
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $props = $this->handle($currentWorkspace);

        return Inertia::render('teammate/Create', $props->toArray());
    }
}
