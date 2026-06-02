<?php

namespace App\Http\Middleware;

use App\Actions\User\TouchSystemUserLastActiveAtAction;
use App\Data\AiRuntime\AiModelOptionData;
use App\Data\SystemUserContextData;
use App\Services\AiRuntime\AiModelResolver;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 共享单租户后台上下文。
 */
class IdentifySystem
{
    /**
     * 注入模型选项解析器和成员活跃时间刷新动作。
     */
    public function __construct(
        private AiModelResolver $modelResolver,
        private TouchSystemUserLastActiveAtAction $touchSystemUserLastActiveAtAction,
    ) {}

    /**
     * 处理传入请求。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $this->touchSystemUserLastActiveAtAction->handle((string) $user->id);

        $systemUserContext = SystemUserContextData::fromUser($user->fresh());
        $systemContext = $systemUserContext->systemContext();

        $request->attributes->set(SystemUserContextData::class, $systemUserContext);
        Inertia::share('systemUserContext', $systemUserContext->toArray());
        Inertia::share('canAccessManageCenter', true);
        Inertia::share('canManageAi', true);
        Inertia::share(
            'aiAssistantLlmModelOptions',
            array_map(
                fn (AiModelOptionData $option): array => $option->toArray(),
                $this->modelResolver->getActiveLlmModelOptions($systemContext),
            ),
        );

        return $next($request);
    }
}
