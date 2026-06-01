<?php

namespace App\Actions\Workspace;

use App\Data\SimplePaginationData;
use App\Data\Workspace\ShowWorkspaceTrashPagePropsData;
use App\Data\Workspace\TrashWorkspaceData;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询已删除工作区列表。
 */
class GetWorkspaceTrashListAction
{
    use AsAction;

    public function handle(int $page = 1, int $perPage = 10): ShowWorkspaceTrashPagePropsData
    {
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);

        $paginator = Workspace::onlyTrashed()
            ->with([
                'owner' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'email']),
            ])
            ->withCount([
                'users' => fn ($query) => $query->withTrashed(),
            ])
            ->orderByDesc('deleted_at')
            ->paginate($perPage, ['id', 'name', 'slug', 'created_at', 'deleted_at', 'owner_id'], 'page', $page);

        $workspaces = $paginator
            ->getCollection()
            ->map(fn (Workspace $w) => TrashWorkspaceData::fromModel($w))
            ->all();

        return new ShowWorkspaceTrashPagePropsData(
            workspace_trash_list: $workspaces,
            workspace_trash_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    public function asController(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);

        return Inertia::render('admin/workspace/Trash', $this->handle($page, $perPage)->toArray());
    }
}
