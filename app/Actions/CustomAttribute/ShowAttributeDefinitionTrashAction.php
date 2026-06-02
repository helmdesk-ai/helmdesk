<?php

namespace App\Actions\CustomAttribute;

use App\Data\CustomAttribute\ListAttributeDefinitionItemData;
use App\Data\CustomAttribute\ShowAttributeDefinitionTrashPagePropsData;
use App\Data\SimplePaginationData;
use App\Data\WorkspaceUserContextData;
use App\Models\AttributeDefinition;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询自定义属性回收站。
 */
class ShowAttributeDefinitionTrashAction
{
    use AsAction;

    public function handle(
        Workspace $workspace,
        int $page = 1,
        int $perPage = 15,
    ): ShowAttributeDefinitionTrashPagePropsData {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));

        $paginator = AttributeDefinition::query()
            ->onlyTrashed()
            ->withCount('contactAttributeValues')
            ->orderByDesc('deleted_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return new ShowAttributeDefinitionTrashPagePropsData(
            trashed_definition_list: $paginator->getCollection()
                ->map(fn (AttributeDefinition $definition) => ListAttributeDefinitionItemData::fromModel($definition))
                ->all(),
            trashed_definition_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    public function asController(Request $request): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();

        return Inertia::render('workspaceSettings/datas/AttributeTrash', $this->handle(
            workspace: $workspace,
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 15),
        )->toArray());
    }
}
