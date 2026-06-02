<?php

namespace App\Actions\CustomAttribute;

use App\Data\SystemUserContextData;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 调整自定义属性的显示顺序。
 */
class ReorderAttributeDefinitionsAction
{
    use AsAction;

    /**
     * @param  string[]  $orderedIds
     */
    public function handle(SystemContext $systemContext, array $orderedIds): void
    {
        $existingIds = $systemContext->attributeDefinitions()
            ->active()
            ->ordered()
            ->pluck('id')
            ->values();

        $submittedIds = collect($orderedIds)
            ->values();

        $isValidOrder = $submittedIds->count() === $existingIds->count()
            && $submittedIds->unique()->count() === $existingIds->count()
            && $submittedIds->diff($existingIds)->isEmpty()
            && $existingIds->diff($submittedIds)->isEmpty();

        if (! $isValidOrder) {
            throw ValidationException::withMessages([
                'ordered_ids' => __('custom_attribute.invalid_reorder_payload'),
            ]);
        }

        DB::transaction(function () use ($systemContext, $orderedIds) {
            foreach ($orderedIds as $index => $id) {
                $systemContext->attributeDefinitions()
                    ->active()
                    ->where('id', $id)
                    ->update(['display_order' => $index]);
            }
        });
    }

    public function asController(Request $request): Response
    {
        $validated = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['required', 'string'],
        ]);

        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();

        $this->handle($systemContext, $validated['ordered_ids']);

        return back();
    }
}
