<?php

namespace App\Actions\Mcp;

use App\Data\EnumOptionData;
use App\Data\Mcp\McpServerData;
use App\Data\Mcp\ShowEditMcpServerPagePropsData;
use App\Data\SystemUserContextData;
use App\Enums\McpTransport;
use App\Enums\UserPermission;
use App\Models\McpServer;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 打开编辑 MCP 服务页面并下发表单初始值。
 */
class ShowEditMcpServerPageAction
{
    use AsAction;

    /**
     * 组装编辑 MCP 服务页面 props。
     */
    public function handle(SystemContext $systemContext, string $slug): ShowEditMcpServerPagePropsData
    {
        /** @var McpServer $server */
        $server = $systemContext->mcpServers()
            ->with(['tools' => fn ($q) => $q->orderBy('name')])
            ->where('slug', $slug)
            ->firstOrFail();

        return new ShowEditMcpServerPagePropsData(
            server: McpServerData::fromModel($server),
            transport_options: EnumOptionData::fromCases(McpTransport::cases()),
        );
    }

    /**
     * 渲染编辑 MCP 服务页面。
     */
    public function asController(Request $request, string $server): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        return Inertia::render('systemSettings/mcpServers/Edit', $this->handle($systemContext, $server)->toArray());
    }
}
