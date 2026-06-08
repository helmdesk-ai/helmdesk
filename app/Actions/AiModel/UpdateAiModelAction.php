<?php

namespace App\Actions\AiModel;

use App\Data\AiModel\FormUpdateAiModelData;
use App\Enums\UserPermission;
use App\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新 AI 模型的名称与启用状态；供应商 / 用途 / model_id 创建后不可变。
 */
class UpdateAiModelAction
{
    use AsAction;

    /**
     * 保存模型可变字段。
     */
    public function handle(string $modelId, FormUpdateAiModelData $data): AiModel
    {
        $model = AiModel::query()->findOrFail($modelId);

        $model->name = $data->name;
        $model->is_active = $data->is_active;
        $model->save();

        return $model;
    }

    /**
     * 鉴权后从请求取表单数据并保存，返回列表页。
     */
    public function asController(Request $request, string $model): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($model, FormUpdateAiModelData::from($request));

        return redirect()->route('admin.manage.ai.models.index');
    }
}
