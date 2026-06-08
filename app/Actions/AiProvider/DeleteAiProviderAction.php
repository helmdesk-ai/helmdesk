<?php

namespace App\Actions\AiProvider;

use App\Enums\UserPermission;
use App\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除全局 AI 供应商及其下所有模型（总后台）。
 *
 * 运行时按用途从全局池取用模型，删除即从池中移除；无引用检查。
 */
class DeleteAiProviderAction
{
    use AsAction;

    /**
     * 连同模型一起删除一条 ai_providers 记录。
     */
    public function handle(string $providerSlug): void
    {
        DB::transaction(function () use ($providerSlug): void {
            $provider = AiProvider::query()->where('slug', $providerSlug)->firstOrFail();
            $provider->models()->delete();
            $provider->delete();
        });
    }

    /**
     * 鉴权后删除并回到列表页。
     */
    public function asController(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($provider);

        return redirect()->route('admin.manage.ai.providers.index');
    }
}
