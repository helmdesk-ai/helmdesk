<?php

namespace App\Actions\CustomAttribute;

use App\Data\WorkspaceUserContextData;
use App\Models\AttributeDefinition;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 归档自定义属性定义。
 */
class ArchiveAttributeDefinitionAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $definitionId): AttributeDefinition
    {
        $definition = $workspace->attributeDefinitions()->findOrFail($definitionId);

        $definition->delete();

        return $definition;
    }

    public function asController(Request $request, string $id): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();

        $this->handle($workspace, $id);

        return back();
    }
}
