<?php

namespace App\Jobs\Mcp;

use App\Actions\Mcp\SyncMcpServerToolsAction;
use App\Enums\McpSyncStatus;
use App\Models\McpServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP 工具缓存同步任务：单次处理一台 MCP 服务。
 */
class SyncMcpServerToolsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    /**
     * 创建单台 MCP 服务同步任务。
     */
    public function __construct(public readonly string $serverId) {}

    /**
     * 执行工具列表同步。
     */
    public function handle(SyncMcpServerToolsAction $syncAction): void
    {
        $server = McpServer::query()->find($this->serverId);
        if ($server === null) {
            Log::info('SyncMcpServerToolsJob: server missing, skipped.', [
                'server_id' => $this->serverId,
            ]);

            return;
        }

        $syncAction->syncServer($server);
    }

    /**
     * 记录队列最终失败，并把页面可见状态置为失败。
     */
    public function failed(Throwable $exception): void
    {
        McpServer::query()
            ->whereKey($this->serverId)
            ->update([
                'last_sync_status' => McpSyncStatus::Failed->value,
                'last_sync_error' => $exception->getMessage(),
                'last_synced_at' => now(),
            ]);

        Log::warning('SyncMcpServerToolsJob failed.', [
            'server_id' => $this->serverId,
            'reason' => $exception->getMessage(),
        ]);
    }
}
