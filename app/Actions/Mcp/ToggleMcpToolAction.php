<?php

namespace App\Actions\Mcp;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 启用或停用某个 MCP 工具供后续 AI 调用使用。
 * 已下线（removed_at!=null）的工具不允许再启用。
 */
class ToggleMcpToolAction
{
    use AsAction;

    /**
     * 翻转 is_enabled，下线工具拒绝启用。
     */
    public function handle(Workspace $workspace, string $serverSlug, string $toolId): McpTool
    {
        /** @var McpServer $server */
        $server = $workspace->mcpServers()->where('slug', $serverSlug)->firstOrFail();
        /** @var McpTool $tool */
        $tool = $server->tools()->where('id', $toolId)->firstOrFail();

        if (! $tool->is_enabled && $tool->removed_at !== null) {
            throw new BusinessException(__('mcp.messages.tool_disabled_due_to_removal'));
        }

        $tool->is_enabled = ! $tool->is_enabled;
        $tool->save();

        return $tool;
    }

    /**
     * 路由入口：仅 manageAi 角色可调用。
     */
    public function asController(Request $request, string $server, string $tool): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $this->handle($workspace, $server, $tool);

        return back();
    }
}
