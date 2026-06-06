<?php

namespace App\Actions\Mcp;

use App\Data\Mcp\McpServerData;
use App\Data\Mcp\ShowSystemMcpServersPagePropsData;
use App\Enums\UserPermission;
use App\Models\McpServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载 MCP 服务列表页数据。
 */
class ShowSystemMcpServersAction
{
    use AsAction;

    /**
     * 装配 MCP 服务列表与工具子项。
     */
    public function handle(): ShowSystemMcpServersPagePropsData
    {
        $servers = McpServer::query()
            ->with(['tools' => fn ($q) => $q->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (McpServer $s) => McpServerData::fromModel($s))
            ->all();

        return new ShowSystemMcpServersPagePropsData(
            servers: $servers,
        );
    }

    /**
     * 渲染系统 MCP 服务列表页。
     */
    public function asController(Request $request): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsView);

        return Inertia::render('systemSettings/mcpServers/Index', $this->handle()->toArray());
    }
}
