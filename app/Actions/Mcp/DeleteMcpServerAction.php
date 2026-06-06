<?php

namespace App\Actions\Mcp;

use App\Enums\UserPermission;
use App\Models\McpServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除系统下指定 MCP 服务及其缓存工具记录。
 */
class DeleteMcpServerAction
{
    use AsAction;

    /**
     * 删除服务，工具记录在事务里一并清理。
     */
    public function handle(string $slug): void
    {
        $server = McpServer::query()->where('slug', $slug)->firstOrFail();

        DB::transaction(function () use ($server): void {
            $server->tools()->delete();
            $server->delete();
        });
    }

    /**
     * 路由入口：需要系统设置编辑权限。
     */
    public function asController(Request $request, string $server): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($server);

        return back();
    }
}
