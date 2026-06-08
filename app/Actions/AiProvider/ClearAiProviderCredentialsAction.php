<?php

namespace App\Actions\AiProvider;

use App\Enums\UserPermission;
use App\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 清空全局 AI 供应商的凭据（总后台）。
 *
 * 凭据清空后该供应商 hasCompleteCredentials() 即为 false，其下模型自动移出运行时取用池。
 */
class ClearAiProviderCredentialsAction
{
    use AsAction;

    /**
     * 清空凭据。
     */
    public function handle(string $providerSlug): AiProvider
    {
        $provider = AiProvider::query()->where('slug', $providerSlug)->firstOrFail();

        $provider->credentials = null;
        $provider->save();

        return $provider;
    }

    /**
     * 鉴权后清空。
     */
    public function asController(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($provider);

        return back();
    }
}
