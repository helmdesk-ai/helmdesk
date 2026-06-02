<?php

namespace App\Actions\Mcp;

use App\Data\Mcp\FormUpdateMcpServerData;
use App\Data\SystemUserContextData;
use App\Models\McpServer;
use App\Models\SystemContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 保存 MCP 服务连接配置（含 endpoint、认证 header、自定义请求头）。
 *
 * 认证 header 合并规则：
 *  - 字段为 null 表示"保留原值"（前端没传该字段）；
 *  - 字段为 ""  表示"清空"；
 *  - 字段为非空字符串则覆盖；
 *  - 合并后任一为空，整组认证 header 清空。
 */
class UpdateMcpServerAction
{
    use AsAction;

    /**
     * 更新一台 MCP 服务，只保存配置，不触发远端连接或工具同步。
     */
    public function handle(SystemContext $systemContext, string $slug, FormUpdateMcpServerData $data): McpServer
    {
        $server = $this->findServer($systemContext, $slug);

        $server->name = $data->name;
        $server->endpoint_url = $data->endpoint_url;
        $server->headers = $this->normalizeHeaders($data->headers);

        if ($data->timeout_seconds !== null) {
            $server->timeout_seconds = $data->timeout_seconds;
        }

        $server->credentials = $this->mergeCredentials($server, $data);
        $server->save();

        return $server->refresh();
    }

    /**
     * 路由入口：仅 manageAi 角色可调用。
     */
    public function asController(Request $request, string $server): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $data = FormUpdateMcpServerData::from($request);
        $this->handle($systemContext, $server, $data);

        return back();
    }

    /**
     * 加载目标服务，找不到时 404。
     */
    private function findServer(SystemContext $systemContext, string $slug): McpServer
    {
        return $systemContext->mcpServers()->where('slug', $slug)->firstOrFail();
    }

    /**
     * 把表单输入合并到当前凭据：clear_auth_credentials 为 true 时直接清空；
     * 否则 null/缺失字段保留原值，非空字符串则覆盖；合并后任一字段空则一并清空。
     *
     * @return array<string, string>|null
     */
    private function mergeCredentials(McpServer $server, FormUpdateMcpServerData $data): ?array
    {
        if ($data->clear_auth_credentials) {
            return null;
        }

        $current = $server->credentials ?? [];
        $currentName = is_string($current['auth_header_name'] ?? null) ? trim($current['auth_header_name']) : '';
        $currentValue = is_string($current['auth_header_value'] ?? null) ? trim($current['auth_header_value']) : '';

        $name = $data->auth_header_name === null ? $currentName : trim($data->auth_header_name);
        $value = $data->auth_header_value === null ? $currentValue : trim($data->auth_header_value);

        if ($name === '' || $value === '') {
            return null;
        }

        return [
            'auth_header_name' => $name,
            'auth_header_value' => $value,
        ];
    }

    /**
     * 归一化自定义请求头：丢弃非字符串值。
     *
     * @param  array<string, mixed>|null  $headers
     * @return array<string, string>|null
     */
    private function normalizeHeaders(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }
            $normalized[$key] = $stringValue;
        }

        return $normalized === [] ? null : $normalized;
    }
}
