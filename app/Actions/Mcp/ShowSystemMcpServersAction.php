<?php

namespace App\Actions\Mcp;

use App\Data\EnumOptionData;
use App\Data\Mcp\McpServerData;
use App\Data\Mcp\ShowSystemMcpServersPagePropsData;
use App\Data\SystemUserContextData;
use App\Enums\McpTransport;
use App\Models\McpServer;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载当前系统下的 MCP 服务和工具列表页面数据。
 */
class ShowSystemMcpServersAction
{
    use AsAction;

    /**
     * 装配 MCP 服务列表 + 工具子项 + 表单下拉选项。
     */
    public function handle(SystemContext $systemContext): ShowSystemMcpServersPagePropsData
    {
        $servers = $systemContext->mcpServers()
            ->with(['tools' => fn ($q) => $q->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (McpServer $s) => McpServerData::fromModel($s))
            ->all();

        return new ShowSystemMcpServersPagePropsData(
            servers: $servers,
            transport_options: EnumOptionData::fromCases(McpTransport::cases()),
        );
    }

    /**
     * 渲染系统 MCP 服务页。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        return Inertia::render('systemSettings/mcpServers/Index', $this->handle($systemContext)->toArray());
    }
}
