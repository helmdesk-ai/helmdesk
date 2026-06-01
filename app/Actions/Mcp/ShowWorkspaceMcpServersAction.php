<?php

namespace App\Actions\Mcp;

use App\Data\EnumOptionData;
use App\Data\Mcp\McpServerData;
use App\Data\Mcp\ShowWorkspaceMcpServersPagePropsData;
use App\Data\WorkspaceUserContextData;
use App\Enums\McpTransport;
use App\Models\McpServer;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载当前工作区下的 MCP 服务和工具列表页面数据。
 */
class ShowWorkspaceMcpServersAction
{
    use AsAction;

    /**
     * 装配 MCP 服务列表 + 工具子项 + 表单下拉选项。
     */
    public function handle(Workspace $workspace): ShowWorkspaceMcpServersPagePropsData
    {
        $servers = $workspace->mcpServers()
            ->with(['tools' => fn ($q) => $q->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (McpServer $s) => McpServerData::fromModel($s))
            ->all();

        return new ShowWorkspaceMcpServersPagePropsData(
            servers: $servers,
            transport_options: EnumOptionData::fromCases(McpTransport::cases()),
        );
    }

    /**
     * 渲染工作区 MCP 服务页。
     */
    public function asController(Request $request): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        return Inertia::render('workspaceSettings/mcpServers/Index', $this->handle($workspace)->toArray());
    }
}
