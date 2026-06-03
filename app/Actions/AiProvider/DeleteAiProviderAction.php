<?php

namespace App\Actions\AiProvider;

use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除系统内自定义 AI 供应商配置。
 */
class DeleteAiProviderAction
{
    use AsAction;

    public function handle(SystemContext $systemContext, string $providerSlug): void
    {
        $provider = $systemContext->aiProviders()->where('slug', $providerSlug)->firstOrFail();

        $resolver = app(AiModelResolver::class);

        if ($resolver->isProviderReferencedByReceptionPlans($provider)) {
            throw new BusinessException(__('ai_runtime.provider_in_use_reception_plan'));
        }

        if ($resolver->isProviderReferencedByKnowledgeBases($provider)) {
            throw new BusinessException(__('knowledge_base.messages.provider_in_use'));
        }

        $provider->models()->delete();
        $provider->delete();
    }

    public function asController(Request $request, string $provider)
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($systemContext, $provider);

        return back();
    }
}
