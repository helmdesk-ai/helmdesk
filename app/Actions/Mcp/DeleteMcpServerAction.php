<?php

namespace App\Actions\Mcp;

use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\SystemContext;
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
    public function handle(SystemContext $systemContext, string $slug): void
    {
        $server = $systemContext->mcpServers()->where('slug', $slug)->firstOrFail();

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
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($systemContext, $server);

        return back();
    }
}
