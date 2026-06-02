<?php

namespace App\Http\Middleware;

use App\Actions\User\TouchWorkspaceUserLastActiveAtAction;
use App\Data\AiRuntime\AiModelOptionData;
use App\Data\WorkspaceUserContextData;
use App\Services\AiRuntime\AiModelResolver;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 共享单租户后台上下文。
 */
class IdentifyWorkspace
{
    /**
     * 注入模型选项解析器和成员活跃时间刷新动作。
     */
    public function __construct(
        private AiModelResolver $modelResolver,
        private TouchWorkspaceUserLastActiveAtAction $touchWorkspaceUserLastActiveAtAction,
    ) {}

    /**
     * 处理传入请求。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $this->touchWorkspaceUserLastActiveAtAction->handle((string) $user->id);

        $workspaceUserContext = WorkspaceUserContextData::fromUser($user->fresh());
        $workspace = $workspaceUserContext->workspace();

        $request->attributes->set(WorkspaceUserContextData::class, $workspaceUserContext);
        Inertia::share('workspaceUserContext', $workspaceUserContext->toArray());
        Inertia::share('canAccessManageCenter', true);
        Inertia::share('canManageAi', true);
        Inertia::share(
            'aiAssistantLlmModelOptions',
            array_map(
                fn (AiModelOptionData $option): array => $option->toArray(),
                $this->modelResolver->getActiveLlmModelOptions($workspace),
            ),
        );

        return $next($request);
    }
}
