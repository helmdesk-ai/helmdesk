<?php

namespace App\Actions\Translation;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\ReceptionPlan;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除工作区翻译供应商（仅限非内置且未被接待方案引用）。
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
    public function handle(Workspace $workspace, string $providerSlug): void
    {
        $provider = $workspace->translationProviders()->where('slug', $providerSlug)->firstOrFail();

        if ($provider->is_builtin) {
            throw new BusinessException(__('translation.cannot_delete_builtin'));
        }

        if ($this->isReferencedByReceptionPlan($workspace, $provider->id)) {
            throw new BusinessException(__('translation.cannot_delete_in_use'));
        }

        $provider->delete();
    }

    /**
     * 判断该供应商是否被本工作区任意接待方案选用。
     */
    private function isReferencedByReceptionPlan(Workspace $workspace, string $providerId): bool
    {
        return ReceptionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->where('translation_config->provider_id', $providerId)
            ->exists();
    }

    /**
     * 鉴权后删除。
     */
    public function asController(Request $request, string $slug, string $provider): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $this->handle($workspace, $provider);

        return back();
    }
}
