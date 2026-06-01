<?php

namespace App\Actions\AiChat;

use App\Enums\McpTransport;
use App\Models\McpServer;
use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 收集当前 workspace 下"启用且配置完整"的 MCP 服务列表，附带各自的启用工具白名单。
 *
 * Go 侧据此为本轮对话挂载 MCP 工具：
 *  - server 维度过滤 `is_active = true` 且有 endpoint；
 *  - tool 维度过滤 `is_enabled = true` 且 `removed_at IS NULL`（去掉已下线工具）；
 *  - 工具白名单为空的 server 直接跳过，避免 Go 侧再访远端 list-tools。
 *
 * 返回值可直接作为 Go 桥接请求的 `mcp_servers` 字段下发。
 *
 * @return array<int, array<string, mixed>>
 */
class CollectActiveMcpServersAction
{
    use AsAction;

    /**
     * 查询并归一化当前 workspace 下所有可用的 MCP 服务及其工具白名单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace): array
    {
        $servers = McpServer::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->whereNotNull('endpoint_url')
            ->where('endpoint_url', '!=', '')
            ->with(['tools' => function ($query): void {
                $query->where('is_enabled', true)->whereNull('removed_at');
            }])
            ->orderBy('sort_order')
            ->get();

        $payload = [];

        foreach ($servers as $server) {
            $toolNames = $server->tools
                ->pluck('name')
                ->filter(fn ($name): bool => is_string($name) && $name !== '')
                ->values()
                ->all();

            if ($toolNames === []) {
                continue;
            }

            $transport = $server->transport instanceof McpTransport
                ? $server->transport->value
                : (string) $server->transport;

            $payload[] = [
                'id' => (string) $server->id,
                'slug' => (string) $server->slug,
                'name' => (string) $server->name,
                'transport' => $transport,
                'endpoint_url' => (string) $server->endpoint_url,
                'credentials' => $this->asJsonObject($this->normalizeMap($server->credentials ?? [])),
                'headers' => $this->asJsonObject($this->normalizeMap($server->headers ?? [])),
                'timeout_seconds' => (int) ($server->timeout_seconds ?? 30),
                'tool_names' => $toolNames,
            ];
        }

        return $payload;
    }

    /**
     * 保证空 string map 序列化为 JSON `{}` 而不是 `[]`。
     *
     * @param  array<string, string>  $map
     */
    private function asJsonObject(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass : $map;
    }

    /**
     * 把任意 key-value map 归一化为纯字符串 map：丢弃非标量值，trim 每个值，跳过空字符串。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function normalizeMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }
            $normalized[$key] = $stringValue;
        }

        return $normalized;
    }
}
