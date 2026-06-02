<?php

namespace App\Actions\Mcp;

use App\Data\WorkspaceUserContextData;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除工作区下指定 MCP 服务及其缓存工具记录。
 */
class DeleteMcpServerAction
{
    use AsAction;

    /**
     * 删除服务，工具记录在事务里一并清理。
     */
    public function handle(Workspace $workspace, string $slug): void
    {
        $server = $workspace->mcpServers()->where('slug', $slug)->firstOrFail();

        DB::transaction(function () use ($server): void {
            $server->tools()->delete();
            $server->delete();
        });
    }

    /**
     * 路由入口：仅 manageAi 角色可调用。
     */
    public function asController(Request $request, string $server): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $this->handle($workspace, $server);

        return back();
    }
}
