<?php

namespace App\Http\Middleware;

use App\Actions\User\TouchWorkspaceUserLastActiveAtAction;
use App\Data\AiRuntime\AiModelOptionData;
use App\Data\Workspace\WorkspaceData;
use App\Data\WorkspaceUserContextData;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 识别当前工作区并共享工作区上下文。
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
        if ($request->user()->is_super_admin) {
            return $next($request);
        }

        $workspaces = $request->user()->workspaces()->get();
        Inertia::share('workspaces', $workspaces->map(fn (Workspace $w) => WorkspaceData::fromModel($w)->toArray())->all());

        $slug = $request->route('slug');
        $path = '/'.ltrim($request->path(), '/');
        $isSettingsPath = str_starts_with($path, '/settings');
        if ($isSettingsPath) {
            $from = $request->query('from_workspace');
            $hasFromWorkspace = is_string($from) && $from !== '';
            if ($hasFromWorkspace) {
                $workspace = $workspaces->firstWhere('slug', $from);
            }
        } else {
            $workspace = $workspaces->firstWhere('slug', $slug);
        }
        if (empty($workspace)) {
            abort(404, '工作区不存在');
        }
        if (! $request->user()->workspaces()->where('workspaces.id', $workspace->id)->exists()) {
            abort(403, '你不是该工作区的成员');
        }

        $this->touchWorkspaceUserLastActiveAtAction->handle($workspace, (string) $request->user()->id);

        $workspaceUserContext = WorkspaceUserContextData::fromModels($workspace, $request->user());
        $request->attributes->set(WorkspaceUserContextData::class, $workspaceUserContext);
        Inertia::share('workspaceUserContext', $workspaceUserContext->toArray());

        $canAccessManageCenter = Gate::allows('workspace.canAccessManageCenter', [$workspace]);
        $canManageAi = Gate::allows('workspace.manageAi', [$workspace]);

        Inertia::share('canAccessManageCenter', $canAccessManageCenter);
        Inertia::share('canManageAi', $canManageAi);
        Inertia::share(
            'aiAssistantLlmModelOptions',
            array_map(
                fn (AiModelOptionData $option): array => $option->toArray(),
                $this->modelResolver->getActiveLlmModelOptions($workspace),
            ),
        );

        if ($request->is('w/*/manage*')) {
            Gate::authorize('workspace.canAccessManageCenter', [$workspace]);
        }

        return $next($request);
    }
}
