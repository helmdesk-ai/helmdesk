<?php

namespace App\Actions\Translation;

use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Models\TranslationProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除系统翻译供应商（仅限非内置）。
 *
 * 内置供应商（is_builtin = true）禁止删除，因为它们由 Catalog 维护、删了会被下次设置页加载再次重建。
 * 接待方案不再引用具体供应商（运行时按全局轮询池取用），删除一家只是缩小池子，故无需引用检查。
 */
class DeleteTranslationProviderAction
{
    use AsAction;

    /**
     * 删除一条 translation_providers 记录。
     */
    public function handle(string $providerSlug): void
    {
        $provider = TranslationProvider::query()->where('slug', $providerSlug)->firstOrFail();

        if ($provider->is_builtin) {
            throw new BusinessException(__('translation.cannot_delete_builtin'));
        }

        $provider->delete();
    }

    /**
     * 鉴权后删除。
     */
    public function asController(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($provider);

        return back();
    }
}
