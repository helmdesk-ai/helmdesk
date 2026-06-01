<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\FormCreateAiModelData;
use App\Data\WorkspaceUserContextData;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为工作区下的 AI 供应商新增（或更新）一个模型。
 */
class CreateAiModelAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $providerSlug, FormCreateAiModelData $data): AiModel
    {
        $provider = $workspace->aiProviders()->where('slug', $providerSlug)->firstOrFail();

        return $this->upsertModel($provider, $data);
    }

    private function upsertModel(AiProvider $provider, FormCreateAiModelData $data): AiModel
    {
        $maxSort = $provider->models()->max('sort_order') ?? -1;
        $model = AiModel::query()->firstOrNew(
            [
                'ai_provider_id' => $provider->id,
                'model_id' => $data->model_id,
                'type' => $data->type,
            ],
        );

        $model->name = $data->name;

        if (! $model->exists) {
            $model->is_builtin = false;
            $model->sort_order = $maxSort + 1;
        }

        $model->save();

        return $model;
    }

    public function asController(Request $request, string $slug, string $provider)
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $data = FormCreateAiModelData::from($request);
        $this->handle($workspace, $provider, $data);

        return back();
    }
}
