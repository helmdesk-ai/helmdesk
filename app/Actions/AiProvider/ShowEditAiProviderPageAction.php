<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\AiProviderData;
use App\Data\AiProvider\ShowEditAiProviderPagePropsData;
use App\Enums\UserPermission;
use App\Models\AiProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染总后台「编辑 AI 供应商」页：下发脱敏后的供应商凭据字段与遮掩值。
 */
class ShowEditAiProviderPageAction
{
    use AsAction;

    /**
     * 按 slug 加载供应商并脱敏后下发。
     */
    public function handle(string $providerSlug): ShowEditAiProviderPagePropsData
    {
        $provider = AiProvider::query()->where('slug', $providerSlug)->firstOrFail();

        return new ShowEditAiProviderPagePropsData(provider: AiProviderData::fromModel($provider));
    }

    /**
     * 鉴权后渲染编辑页。
     */
    public function asController(Request $request, string $provider): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        return Inertia::render('systemSettings/aiProviders/Edit', $this->handle($provider)->toArray());
    }
}
