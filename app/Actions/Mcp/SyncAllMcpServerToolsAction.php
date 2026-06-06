<?php

namespace App\Actions\Mcp;

use App\Enums\UserPermission;
use App\Models\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 异步同步系统内全部 MCP 服务的工具缓存。
 */
class SyncAllMcpServerToolsAction
{
    use AsAction;

    /**
     * 注入单台 MCP 服务同步入队动作。
     */
    public function __construct(
        private readonly QueueMcpServerToolSyncAction $queueToolSync,
    ) {}

    /**
     * 标记所有 MCP 服务进入同步中状态，并逐台派发同步任务。
     *
     * @return array{queued: int}
     */
    public function handle(): array
    {
        $servers = McpServer::query()
            ->orderBy('sort_order')
            ->get(['id']);

        foreach ($servers as $server) {
            $this->queueToolSync->handle($server);
        }

        return ['queued' => $servers->count()];
    }

    /**
     * 路由入口：返回已入队数量，页面通过列表轮询观察每台服务状态。
     */
    public function asController(Request $request): JsonResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $result = $this->handle();

        return response()->json([
            'success' => true,
            'message' => __('mcp.messages.sync_all_queued', ['count' => $result['queued']]),
            'queued' => $result['queued'],
        ]);
    }
}
