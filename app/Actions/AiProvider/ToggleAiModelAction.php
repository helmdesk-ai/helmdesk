<?php

namespace App\Actions\AiProvider;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 启用或停用工作区供应商下的单个 AI 模型。
 */
class ToggleAiModelAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $providerSlug, string $modelId): AiModel
    {
        $model = AiModel::query()
            ->whereHas(
                'provider',
                fn ($q) => $q
                    ->where('slug', $providerSlug),
            )
            ->where('id', $modelId)
            ->firstOrFail();

        if ($model->is_active) {
            $resolver = app(AiModelResolver::class);

            if ($resolver->isModelReferencedByReceptionPlans($model->id)) {
                throw new BusinessException(__('ai_runtime.model_in_use_reception_plan'));
            }

            if ($resolver->isModelReferencedByKnowledgeBases($model->id)) {
                throw new BusinessException(__('knowledge_base.messages.model_in_use'));
            }
        }

        if (
            $model->type === 'llm'
            && $model->is_active
            && ! $this->providerHasAnotherActiveLlmModel($model)
        ) {
            throw new BusinessException(__('ai.disable_requires_active_model'));
        }

        $model->is_active = ! $model->is_active;
        $model->save();

        return $model;
    }

    public function asController(Request $request, string $provider, string $model)
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $this->handle($workspace, $provider, $model);

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
