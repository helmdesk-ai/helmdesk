<?php

namespace App\Actions\AiModel;

use App\Enums\AiModelPurpose;
use App\Enums\UserPermission;
use App\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 重排某用途下模型的主备优先级：接收该用途完整的有序 model id 列表，按下标写回 sort_order。
 */
class ReorderAiModelsAction
{
    use AsAction;

    /**
     * @param  string[]  $orderedIds
     */
    public function handle(AiModelPurpose $purpose, array $orderedIds): void
    {
        $existingIds = AiModel::query()->where('purpose', $purpose->value)->pluck('id')->values();
        $submittedIds = collect($orderedIds)->values();

        $isValidOrder = $submittedIds->count() === $existingIds->count()
            && $submittedIds->unique()->count() === $existingIds->count()
            && $submittedIds->diff($existingIds)->isEmpty()
            && $existingIds->diff($submittedIds)->isEmpty();

        if (! $isValidOrder) {
            throw ValidationException::withMessages([
                'ordered_ids' => __('ai.models_invalid_reorder'),
            ]);
        }

        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $modelId) {
                AiModel::query()->whereKey($modelId)->update(['sort_order' => $index]);
            }
        });
    }

    /**
     * 鉴权、校验提交的用途与有序列表后重排，返回上一页。
     */
    public function asController(Request $request): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $validated = $request->validate([
            'purpose' => ['required', Rule::enum(AiModelPurpose::class)],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['required', 'string'],
        ]);

        $this->handle(AiModelPurpose::from($validated['purpose']), $validated['ordered_ids']);

        return back();
    }
}
