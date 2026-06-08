<?php

namespace App\Actions\AiModel;

use App\Data\AiModel\AiModelListItemData;
use App\Data\AiModel\ShowEditAiModelPagePropsData;
use App\Enums\UserPermission;
use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染总后台「编辑 AI 模型」页：下发当前模型（供应商 / 用途 / model_id 只读，仅可改名称与启用）。
 */
class ShowEditAiModelPageAction
{
    use AsAction;

    /**
     * 加载模型。
     */
    public function handle(string $modelId): ShowEditAiModelPagePropsData
    {
        $model = AiModel::query()->with('provider')->findOrFail($modelId);

        return new ShowEditAiModelPagePropsData(
            model: AiModelListItemData::fromModel($model),
        );
    }

    /**
     * 鉴权后渲染编辑模型页。
     */
    public function asController(Request $request, string $model): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        return Inertia::render('systemSettings/aiModels/Edit', $this->handle($model)->toArray());
    }
}
