<?php

namespace App\Actions\AiProvider;

use App\Data\SystemUserContextData;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除系统供应商下的模型配置。
 */
class DeleteAiModelAction
{
    use AsAction;

    public function handle(SystemContext $systemContext, string $providerSlug, string $modelId): void
    {
        $model = AiModel::query()
            ->whereHas(
                'provider',
                fn ($q) => $q
                    ->where('slug', $providerSlug),
            )
            ->where('id', $modelId)
            ->firstOrFail();

        abort_if($model->is_builtin, 403, __('ai.cannot_delete_builtin_model'));

        $resolver = app(AiModelResolver::class);

        if ($resolver->isModelReferencedByReceptionPlans($model->id)) {
            throw new BusinessException(__('ai_runtime.model_in_use_reception_plan'));
        }

        if ($resolver->isModelReferencedByKnowledgeBases($model->id)) {
            throw new BusinessException(__('knowledge_base.messages.model_in_use'));
        }

        if (
            $model->type === 'llm'
            && $model->is_active
            && ! $this->providerHasAnotherActiveLlmModel($model)
        ) {
            throw new BusinessException(__('ai.disable_requires_active_model'));
        }

        $model->delete();
    }

    public function asController(Request $request, string $provider, string $model)
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $this->handle($systemContext, $provider, $model);

        return back();
    }

    private function providerHasAnotherActiveLlmModel(AiModel $model): bool
    {
        return AiModel::query()
            ->where('ai_provider_id', $model->ai_provider_id)
            ->where('type', 'llm')
            ->where('is_active', true)
            ->whereKeyNot($model->id)
            ->exists();
    }
}
