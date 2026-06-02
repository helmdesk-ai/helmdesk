<?php

namespace App\Actions\Reception\Plan;

use App\Enums\McpTransport;
use App\Models\McpServer;
use App\Models\McpTool;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 按接待方案选中的 mcp_tool_ids 收集运行时可挂载的 MCP 服务列表。
 *
 * 与 chat_stream 路径上的 CollectActiveMcpServersAction 不同：这里以「方案级 tool 白名单」为入口，
 * 反查工具所属 server 并把同台 server 的工具聚合，再过滤掉 server 已停用 / 工具被禁用或下线的项，
 * 返回结构与 Go aitools.McpServerSpec 一一对应，可直接通过 Bridge 下发给任务 agent。
 */
class CollectPlanMcpServersAction
{
    use AsAction;

    /**
     * 按工具 ID 列表聚合服务列表，丢弃不可用工具。
     *
     * @param  list<string>  $mcpToolIds
     * @return list<array<string, mixed>>
     */
    public function handle(array $mcpToolIds): array
    {
        if ($mcpToolIds === []) {
            return [];
        }

        $tools = McpTool::query()
            ->with('server')
            ->whereIn('id', $mcpToolIds)
            ->where('is_enabled', true)
            ->whereNull('removed_at')
            ->whereHas('server', fn ($q) => $q->where('is_active', true)
                ->whereNotNull('endpoint_url')
                ->where('endpoint_url', '!=', '')
            )
            ->get();

        /** @var array<string, array{server: McpServer, tool_names: list<string>}> $byServer */
        $byServer = [];
        foreach ($tools as $tool) {
            $server = $tool->server;
            if ($server === null) {
                continue;
            }
            $key = (string) $server->id;
            if (! isset($byServer[$key])) {
                $byServer[$key] = ['server' => $server, 'tool_names' => []];
            }
            if (is_string($tool->name) && $tool->name !== '') {
                $byServer[$key]['tool_names'][] = $tool->name;
            }
        }

        $payload = [];
        foreach ($byServer as $entry) {
            $server = $entry['server'];
            $toolNames = array_values(array_unique($entry['tool_names']));
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
     * 空 string map 序列化为 JSON `{}` 而不是 `[]`，与 Go map[string]string 解码兼容。
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
