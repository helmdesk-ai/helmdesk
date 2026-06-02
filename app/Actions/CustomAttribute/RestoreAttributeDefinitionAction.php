<?php

namespace App\Actions\CustomAttribute;

use App\Data\SystemUserContextData;
use App\Models\AttributeDefinition;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 恢复已归档的自定义属性定义。
 */
class RestoreAttributeDefinitionAction
{
    use AsAction;

    public function handle(SystemContext $systemContext, string $definitionId): AttributeDefinition
    {
        $definition = AttributeDefinition::query()
            ->onlyTrashed()
            ->findOrFail($definitionId);

        $maxOrder = $systemContext->attributeDefinitions()
            ->active()
            ->max('display_order') ?? -1;

        $definition->display_order = $maxOrder + 1;
        $definition->restore();
        $definition->save();

        return $definition;
    }

    public function asController(Request $request, string $id): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();

        $this->handle($systemContext, $id);

        return back();
    }
}
