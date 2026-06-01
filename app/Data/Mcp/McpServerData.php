<?php

namespace App\Data\Mcp;

use App\Enums\McpSyncStatus;
use App\Enums\McpTransport;
use App\Models\McpServer;
use Spatie\LaravelData\Data;

/**
 * MCP 服务详情数据。
 * 同时承担左侧列表行 + 右侧详情面板的数据来源，由 ShowWorkspaceMcpServersAction 装配。
 * 认证 header 的 name 明文下发（前端反推 Bearer / Custom preset 需要），value 不下发（敏感）。
 */
class McpServerData extends Data
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public McpTransport $transport,
        public string $transport_label,
        public string $endpoint_url,
        public ?string $auth_header_name,
        public bool $has_auth_credentials,
        /** @var array<string, string>|null */
        public ?array $headers,
        public bool $is_active,
        public int $timeout_seconds,
        public ?string $last_synced_at,
        public McpSyncStatus $last_sync_status,
        public string $last_sync_status_label,
        public ?string $last_sync_error,
        public int $tools_count,
        public int $removed_tools_count,
        public int $sort_order,
        /** @var McpToolData[] */
        public array $tools,
    ) {}

    /**
     * 从模型装配展示数据；tools 关系若已 eager-load 则一并下发详情面板。
     */
    public static function fromModel(McpServer $server): self
    {
        $tools = [];
        $toolsCount = 0;
        $removedCount = 0;

        if ($server->relationLoaded('tools')) {
            foreach ($server->tools as $tool) {
                $tools[] = McpToolData::fromModel($tool);
                $toolsCount++;
                if ($tool->removed_at !== null) {
                    $removedCount++;
                }
            }
        } else {
            $toolsCount = (int) ($server->tools_count ?? 0);
        }

        $credentials = $server->credentials ?? [];
        $authHeaderName = isset($credentials['auth_header_name']) && is_string($credentials['auth_header_name'])
            ? $credentials['auth_header_name']
            : null;
        $hasAuthCredentials = $authHeaderName !== null
            && isset($credentials['auth_header_value'])
            && is_string($credentials['auth_header_value'])
            && $credentials['auth_header_value'] !== '';

        return new self(
            id: (string) $server->id,
            slug: (string) $server->slug,
            name: (string) $server->name,
            transport: $server->transport,
            transport_label: $server->transport->label(),
            endpoint_url: (string) $server->endpoint_url,
            auth_header_name: $authHeaderName,
            has_auth_credentials: $hasAuthCredentials,
            headers: $server->headers,
            is_active: (bool) $server->is_active,
            timeout_seconds: (int) $server->timeout_seconds,
            last_synced_at: $server->last_synced_at?->toIso8601String(),
            last_sync_status: $server->last_sync_status,
            last_sync_status_label: $server->last_sync_status->label(),
            last_sync_error: $server->last_sync_error,
            tools_count: $toolsCount,
            removed_tools_count: $removedCount,
            sort_order: (int) $server->sort_order,
            tools: $tools,
        );
    }
}
