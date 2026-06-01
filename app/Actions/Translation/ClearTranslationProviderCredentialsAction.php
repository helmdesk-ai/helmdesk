<?php

namespace App\Actions\Translation;

use App\Data\WorkspaceUserContextData;
use App\Models\TranslationProvider;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 清空翻译供应商的凭据（用户主动忘记密钥用）。
 *
 * 凭据清空后该供应商的 hasCompleteCredentials() 即为 false，仍引用它的接待方案会在运行时按降级路径处理。
 */
class ClearTranslationProviderCredentialsAction
{
    use AsAction;

    /**
     * 清空凭据。
     */
    public function handle(Workspace $workspace, string $providerSlug): TranslationProvider
    {
        $provider = $workspace->translationProviders()->where('slug', $providerSlug)->firstOrFail();

        $provider->credentials = null;
        $provider->save();

        return $provider;
    }

    /**
     * 鉴权后清空。
     */
    public function asController(Request $request, string $slug, string $provider): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $this->handle($workspace, $provider);

        return back();
    }
}
