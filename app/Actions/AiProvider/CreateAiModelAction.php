<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\FormCreateAiModelData;
use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为系统下的 AI 供应商新增（或更新）一个模型。
 */
class CreateAiModelAction
{
    use AsAction;

    public function handle(SystemContext $systemContext, string $providerSlug, FormCreateAiModelData $data): AiModel
    {
        $provider = $systemContext->aiProviders()->where('slug', $providerSlug)->firstOrFail();

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

    public function asController(Request $request, string $provider)
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $data = FormCreateAiModelData::from($request);
        $this->handle($systemContext, $provider, $data);

        return back();
    }
}
