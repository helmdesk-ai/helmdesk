<?php

namespace App\Actions\AiModel;

use App\Enums\UserPermission;
use App\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 切换 AI 模型的启用状态（列表内联 Switch）；停用即不参与运行时取用。
 */
class ToggleAiModelAction
{
    use AsAction;

    /**
     * 翻转启用状态。
     */
    public function handle(string $modelId): AiModel
    {
        $model = AiModel::query()->findOrFail($modelId);
        $model->is_active = ! $model->is_active;
        $model->save();

        return $model;
    }

    /**
     * 鉴权后切换并返回上一页。
     */
    public function asController(Request $request, string $model): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($model);

        return back();
    }
}
