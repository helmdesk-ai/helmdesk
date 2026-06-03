<?php

namespace App\Actions\Translation;

use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Models\ReceptionPlan;
use App\Models\SystemContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除系统翻译供应商（仅限非内置且未被接待方案引用）。
 *
 * 内置供应商（is_builtin = true）禁止删除，因为它们由 Catalog 维护、删了会被下次设置页加载再次重建。
 * 渠道默认跟随接待方案最新版，故引用检查只看当前 reception_plans 草稿行的 translation_config.provider_id。
 */
class DeleteTranslationProviderAction
{
    use AsAction;

    /**
     * 删除一条 translation_providers 记录。
     */
    public function handle(SystemContext $systemContext, string $providerSlug): void
    {
        $provider = $systemContext->translationProviders()->where('slug', $providerSlug)->firstOrFail();

        if ($provider->is_builtin) {
            throw new BusinessException(__('translation.cannot_delete_builtin'));
        }

        if ($this->isReferencedByReceptionPlan($systemContext, $provider->id)) {
            throw new BusinessException(__('translation.cannot_delete_in_use'));
        }

        $provider->delete();
    }

    /**
     * 判断该供应商是否被本系统任意接待方案选用。
     */
    private function isReferencedByReceptionPlan(SystemContext $systemContext, string $providerId): bool
    {
        return ReceptionPlan::query()
            ->where('translation_config->provider_id', $providerId)
            ->exists();
    }

    /**
     * 鉴权后删除。
     */
    public function asController(Request $request, string $provider): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($systemContext, $provider);

        return back();
    }
}
