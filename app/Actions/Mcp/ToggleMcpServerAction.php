<?php

namespace App\Actions\Mcp;

use App\Data\SystemUserContextData;
use App\Exceptions\BusinessException;
use App\Models\McpServer;
use App\Models\SystemContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 启用或停用系统下的 MCP 服务。
 * 启用前要求至少配置了 endpoint URL；连接是否实际可用由测试连接按钮承担，
 * 这里只防止"明显未配置完整"的服务被点亮。
 */
class ToggleMcpServerAction
{
    use AsAction;

    /**
     * 翻转 is_active。
     */
    public function handle(SystemContext $systemContext, string $slug): McpServer
    {
        $server = $systemContext->mcpServers()->where('slug', $slug)->firstOrFail();

        if (! $server->is_active && trim((string) $server->endpoint_url) === '') {
            throw new BusinessException(__('mcp.messages.cannot_toggle_without_endpoint'));
        }

        $server->is_active = ! $server->is_active;
        $server->save();

        return $server;
    }

    /**
     * 路由入口：仅 manageAi 角色可调用。
     */
    public function asController(Request $request, string $server): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $this->handle($systemContext, $server);

        return back();
    }
}
