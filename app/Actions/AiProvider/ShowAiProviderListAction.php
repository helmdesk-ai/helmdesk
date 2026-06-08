<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\AiProviderData;
use App\Data\AiProvider\ShowAiProviderListPagePropsData;
use App\Enums\UserPermission;
use App\Models\AiProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询全局 AI 供应商列表（总后台，纯凭据，对齐翻译供应商）。
 *
 * 渲染 resources/js/pages/systemSettings/aiProviders/Index.vue：表格展示全部供应商及凭据完整度；
 * 模型在「AI 模型管理」页单独维护。
 */
class ShowAiProviderListAction
{
    use AsAction;

    /**
     * 组装供应商列表。
     */
    public function handle(): ShowAiProviderListPagePropsData
    {
        $providers = AiProvider::query()
            ->orderBy('name')
            ->get()
            ->map(fn (AiProvider $provider) => AiProviderData::fromModel($provider))
            ->all();

        return new ShowAiProviderListPagePropsData(providers: $providers);
    }

    /**
     * 鉴权后渲染 AI 供应商列表页。
     */
    public function asController(Request $request): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsView);

        return Inertia::render('systemSettings/aiProviders/Index', $this->handle()->toArray());
    }
}
