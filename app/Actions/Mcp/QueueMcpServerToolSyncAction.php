<?php

namespace App\Actions\Mcp;

use App\Enums\McpSyncStatus;
use App\Jobs\Mcp\SyncMcpServerToolsJob;
use App\Models\McpServer;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 将单台 MCP 服务的工具同步任务放入队列。
 */
class QueueMcpServerToolSyncAction
{
    use AsAction;

    /**
     * 标记 MCP 服务进入同步中状态，并派发工具同步任务。
     */
    public function handle(McpServer $server): void
    {
        McpServer::query()
            ->whereKey($server->id)
            ->update([
                'last_sync_status' => McpSyncStatus::Syncing->value,
                'last_sync_error' => null,
            ]);

        SyncMcpServerToolsJob::dispatch((string) $server->id)->afterCommit();
    }
}
