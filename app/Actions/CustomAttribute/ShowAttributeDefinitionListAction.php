<?php

namespace App\Actions\CustomAttribute;

use App\Data\CustomAttribute\ListAttributeDefinitionItemData;
use App\Data\CustomAttribute\ShowListAttributeDefinitionPagePropsData;
use App\Data\EnumOptionData;
use App\Data\WorkspaceUserContextData;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示自定义属性定义列表。
 */
class ShowAttributeDefinitionListAction
{
    use AsAction;

    public function handle(Workspace $workspace): ShowListAttributeDefinitionPagePropsData
    {
        $definitions = $workspace->attributeDefinitions()
            ->withCount('contactAttributeValues')
            ->ordered()
            ->get();

        return new ShowListAttributeDefinitionPagePropsData(
            definition_list: $definitions
                ->map(fn (AttributeDefinition $definition) => ListAttributeDefinitionItemData::fromModel($definition))
                ->all(),
            type_options: EnumOptionData::fromCases(AttributeType::cases()),
        );
    }

    public function asController(Request $request): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        $props = $this->handle($workspace);

        return Inertia::render('workspaceSettings/datas/Attribute', $props->toArray());
    }
}
