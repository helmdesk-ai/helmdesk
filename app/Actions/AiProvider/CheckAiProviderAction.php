<?php

namespace App\Actions\AiProvider;

use App\Data\WorkspaceUserContextData;
use App\Models\AiProvider;
use App\Models\Workspace;
use App\Services\AiRuntime\GoAiRuntimeBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 校验工作区下指定 AI 供应商凭据和连接是否可用。
 */
class CheckAiProviderAction
{
    use AsAction;

    public function __construct(
        public GoAiRuntimeBridge $runtimeBridge,
    ) {}

    /**
     * @param  array<string, mixed>|null  $configuration
     * @return array{success: bool, message: string}
     */
    public function handle(Workspace $workspace, string $providerSlug, ?array $configuration = null): array
    {
        $provider = $this->findProvider($workspace, $providerSlug);

        if (! $this->hasActiveLlmModel($provider)) {
            return ['success' => false, 'message' => __('ai.check_no_model')];
        }

        $credentials = $provider->mergeCredentials($configuration ?? []);
        $result = $this->runtimeBridge->checkProviderConnection($provider, $credentials);

        if (! ($result['supported'] ?? false)) {
            return ['success' => false, 'message' => __('ai.check_unsupported_protocol')];
        }

        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? __('ai.check_empty_response')),
        ];
    }

    private function findProvider(Workspace $workspace, string $slug): AiProvider
    {
        return $workspace->aiProviders()->where('slug', $slug)->firstOrFail();
    }

    private function hasActiveLlmModel(AiProvider $provider): bool
    {
        return $provider->models()
            ->where('type', 'llm')
            ->where('is_active', true)
            ->exists();
    }

    public function asController(Request $request, string $provider): JsonResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $configuration = $request->input('configuration');

        return response()->json($this->handle(
            $workspace,
            $provider,
            is_array($configuration) ? $configuration : null,
        ));
    }
}
