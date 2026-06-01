<?php

namespace App\Actions\Tag;

use App\Data\SimplePaginationData;
use App\Data\Tag\ListTagItemData;
use App\Data\Tag\ShowTagTrashPagePropsData;
use App\Data\WorkspaceUserContextData;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询标签回收站。
 */
class ShowTagTrashAction
{
    use AsAction;

    /**
     * 查询当前工作区已删除标签列表。
     */
    public function handle(
        Workspace $workspace,
        int $page = 1,
        int $perPage = 15,
    ): ShowTagTrashPagePropsData {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));

        $paginator = Tag::query()
            ->where('workspace_id', $workspace->id)
            ->onlyTrashed()
            ->withCount(['contacts', 'conversations'])
            // 标签组可能已被软删，回收站仍要展示其维度，故连带 trashed 一起加载。
            ->with(['tagGroup' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('deleted_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return new ShowTagTrashPagePropsData(
            trashed_tag_list: $paginator->getCollection()
                ->map(fn (Tag $tag) => ListTagItemData::fromModel(
                    $tag,
                    (int) ($tag->contacts_count ?? 0),
                ))
                ->all(),
            trashed_tag_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    /**
     * 返回标签回收站页面。
     */
    public function asController(Request $request): Response
    {
        $currentWorkspace = WorkspaceUserContextData::fromRequest($request)->workspace();

        return Inertia::render('tags/Trash', $this->handle(
            workspace: $currentWorkspace,
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 15),
        )->toArray());
    }
}
