<?php

namespace Database\Factories;

use App\Enums\McpSyncStatus;
use App\Enums\McpTransport;
use App\Models\McpServer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpServer>
 */
class McpServerFactory extends Factory
{
    /**
     * MCP 服务的默认状态：禁用、无凭据，便于测试控制启用时机和凭据走读。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company().' MCP';

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'transport' => McpTransport::StreamableHttp,
            'endpoint_url' => 'https://'.fake()->domainName().'/mcp',
            'credentials' => null,
            'headers' => null,
            'is_active' => false,
            'timeout_seconds' => 30,
            'last_synced_at' => null,
            'last_sync_status' => McpSyncStatus::Pending,
            'last_sync_error' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * 写入 Bearer Token 凭据（落库即标准 Authorization: Bearer <token> header 对）。
     */
    public function withBearerToken(string $token = 'test-token-xxx'): self
    {
        return $this->state([
            'credentials' => [
                'auth_header_name' => 'Authorization',
                'auth_header_value' => 'Bearer '.$token,
            ],
        ]);
    }

    /**
     * 写入自定义认证 header 凭据。
     */
    public function withAuthHeader(string $name, string $value): self
    {
        return $this->state([
            'credentials' => [
                'auth_header_name' => $name,
                'auth_header_value' => $value,
            ],
        ]);
    }

    /**
     * 切换到已启用状态。
     */
    public function active(): self
    {
        return $this->state(['is_active' => true]);
    }

    /**
     * 标记一次成功同步。
     */
    public function synced(): self
    {
        return $this->state([
            'last_synced_at' => now(),
            'last_sync_status' => McpSyncStatus::Success,
            'last_sync_error' => null,
        ]);
    }
}
