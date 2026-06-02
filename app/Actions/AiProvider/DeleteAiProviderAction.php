<?php

namespace App\Actions\AiProvider;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除工作区内自定义 AI 供应商配置。
 */
class DeleteAiProviderAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $providerSlug): void
    {
        $provider = $workspace->aiProviders()->where('slug', $providerSlug)->firstOrFail();

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
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $this->handle($workspace, $provider);

        return back();
    }
}
