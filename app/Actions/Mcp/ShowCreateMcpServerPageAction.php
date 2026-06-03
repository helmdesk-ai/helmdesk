<?php

namespace App\Actions\Mcp;

use App\Data\EnumOptionData;
use App\Data\Mcp\ShowCreateMcpServerPagePropsData;
use App\Data\SystemUserContextData;
use App\Enums\McpTransport;
use App\Enums\UserPermission;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 打开创建 MCP 服务页面并下发表单选项。
 */
class ShowCreateMcpServerPageAction
{
    use AsAction;

    /**
     * 组装创建 MCP 服务页面 props。
     */
    public function handle(SystemContext $systemContext): ShowCreateMcpServerPagePropsData
    {
        return new ShowCreateMcpServerPagePropsData(
            transport_options: EnumOptionData::fromCases(McpTransport::cases()),
        );
    }

    /**
     * 渲染创建 MCP 服务页面。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        return Inertia::render('systemSettings/mcpServers/Create', $this->handle($systemContext)->toArray());
    }
}
