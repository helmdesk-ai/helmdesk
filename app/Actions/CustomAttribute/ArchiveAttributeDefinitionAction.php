<?php

namespace App\Actions\CustomAttribute;

use App\Data\SystemUserContextData;
use App\Models\AttributeDefinition;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 归档自定义属性定义。
 */
class ArchiveAttributeDefinitionAction
{
    use AsAction;

    public function handle(SystemContext $systemContext, string $definitionId): AttributeDefinition
    {
        $definition = $systemContext->attributeDefinitions()->findOrFail($definitionId);

        $definition->delete();

        return $definition;
    }

    public function asController(Request $request, string $id): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();

        $this->handle($systemContext, $id);

        return back();
    }
}
