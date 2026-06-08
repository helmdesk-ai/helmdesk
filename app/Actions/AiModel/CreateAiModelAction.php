<?php

namespace App\Actions\AiModel;

use App\Data\AiModel\FormCreateAiModelData;
use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 新增全局 AI 模型（一行=一个模型+一个用途）：选供应商 + 用途 + model_id + 名称。
 * type 由用途的能力类型派生；sort_order 取同用途末尾（新模型排在该用途最后）。
 */
class CreateAiModelAction
{
    use AsAction;

    /**
     * 在指定供应商下写入模型。
     */
    public function handle(FormCreateAiModelData $data): AiModel
    {
        $provider = AiProvider::query()->findOrFail($data->ai_provider_id);

        $duplicate = AiModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model_id', $data->model_id)
            ->where('purpose', $data->purpose->value)
            ->exists();
        if ($duplicate) {
            throw new BusinessException(__('ai.model_purpose_exists'));
        }

        return $provider->models()->create([
            'model_id' => $data->model_id,
            'name' => $data->name,
            'type' => $data->purpose->modelType()->value,
            'purpose' => $data->purpose->value,
            'is_active' => true,
            'sort_order' => AiModel::query()->where('purpose', $data->purpose->value)->count(),
        ]);
    }

    /**
     * 鉴权、校验表单并落库后返回模型列表页。
     */
    public function asController(Request $request): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle(FormCreateAiModelData::from($request));

        return redirect()->route('admin.manage.ai.models.index');
    }
}
