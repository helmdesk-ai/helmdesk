<?php

namespace App\Actions\Translation;

use App\Enums\UserPermission;
use App\Models\TranslationProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 启用或停用单个系统翻译供应商。
 *
 * 仅启用且凭据完整的供应商进入运行时翻译轮询池（见 TranslationProviderPool）；
 * 接待方案不再引用具体供应商，停用任意一家只是缩小池子，故无需「被引用」校验。
 */
class ToggleTranslationProviderAction
{
    use AsAction;

    /**
     * 翻转指定供应商的启用状态。
     */
    public function handle(string $providerSlug): TranslationProvider
    {
        $provider = TranslationProvider::query()->where('slug', $providerSlug)->firstOrFail();

        $provider->is_active = ! $provider->is_active;
        $provider->save();

        return $provider;
    }

    /**
     * 鉴权后翻转启用状态并返回列表页。
     */
    public function asController(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($provider);

        return back();
    }
}
