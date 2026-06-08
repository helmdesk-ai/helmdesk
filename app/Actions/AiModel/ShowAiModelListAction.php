<?php

namespace App\Actions\AiModel;

use App\Data\AiModel\AiModelListItemData;
use App\Data\AiModel\ShowAiModelListPagePropsData;
use App\Data\EnumOptionData;
use App\Enums\AiModelPurpose;
use App\Enums\UserPermission;
use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染总后台「AI 模型管理」列表页：跨供应商全量模型（前端按 purpose 分 Tab、Tab 内按 sort_order 排序）。
 */
class ShowAiModelListAction
{
    use AsAction;

    /**
     * 组装模型列表与用途 Tab。
     */
    public function handle(): ShowAiModelListPagePropsData
    {
        $models = AiModel::query()
            ->with('provider')
            ->orderBy('purpose')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AiModel $model) => AiModelListItemData::fromModel($model))
            ->all();

        return new ShowAiModelListPagePropsData(
            models: $models,
            purpose_tabs: EnumOptionData::fromCases(AiModelPurpose::cases()),
        );
    }

    /**
     * 鉴权后渲染 AI 模型管理列表页。
     */
    public function asController(Request $request): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsView);

        return Inertia::render('systemSettings/aiModels/List', $this->handle()->toArray());
    }
}
