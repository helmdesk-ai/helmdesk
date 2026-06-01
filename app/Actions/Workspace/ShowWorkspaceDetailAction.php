<?php

namespace App\Actions\Workspace;

use App\Data\CurrentWorkspace\ShowWorkspaceDetailPagePropsData;
use App\Data\CurrentWorkspace\WorkspaceDetailData;
use App\Data\CurrentWorkspace\WorkspaceMemberData;
use App\Data\CurrentWorkspace\WorkspaceMembersData;
use App\Data\EnumOptionData;
use App\Data\SimplePaginationData;
use App\Data\User\UserOptionData;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示工作区详情和成员列表。
 */
class ShowWorkspaceDetailAction
{
    use AsAction;

    public function handle(string $id, int $page = 1, int $perPage = 10): ShowWorkspaceDetailPagePropsData
    {
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);

        $workspace = Workspace::query()
            ->with([
                'owner' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'email']),
            ])
            ->findOrFail($id);

        $memberIds = $workspace->users()
            ->withTrashed()
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

        $paginator = $workspace->users()
            ->withTrashed()
            ->orderByPivot('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $members = $paginator
            ->getCollection()
            ->map(fn (User $u) => WorkspaceMemberData::fromModel($u))
            ->all();

        return new ShowWorkspaceDetailPagePropsData(
            workspace: WorkspaceDetailData::fromModel($workspace, $paginator->total()),
            members: new WorkspaceMembersData(
                items: $members,
                pagination: SimplePaginationData::fromPaginator($paginator),
            ),
            role_options: EnumOptionData::fromCases(WorkspaceRole::assignableCases()),
            available_users: $availableUsers,
        );
    }

    public function asController(Request $request, string $id)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);

        return Inertia::render('admin/workspace/Show', $this->handle($id, $page, $perPage)->toArray());
    }
}
