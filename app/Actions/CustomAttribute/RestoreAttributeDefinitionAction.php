<?php

namespace App\Actions\CustomAttribute;

use App\Data\WorkspaceUserContextData;
use App\Models\AttributeDefinition;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 恢复已归档的自定义属性定义。
 */
class RestoreAttributeDefinitionAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $definitionId): AttributeDefinition
    {
        $definition = AttributeDefinition::query()
            ->where('workspace_id', $workspace->id)
            ->onlyTrashed()
            ->findOrFail($definitionId);

        $maxOrder = $workspace->attributeDefinitions()
            ->active()
            ->max('display_order') ?? -1;

        $definition->display_order = $maxOrder + 1;
        $definition->restore();
        $definition->save();

        return $definition;
    }

    public function asController(Request $request, string $slug, string $id): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();

        $this->handle($workspace, $id);

        return back();
    }
}
