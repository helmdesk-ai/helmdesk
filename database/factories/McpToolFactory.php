<?php

namespace Database\Factories;

use App\Models\McpServer;
use App\Models\McpTool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpTool>
 */
class McpToolFactory extends Factory
{
    /**
     * 一个最小可用工具的默认状态：启用、已 last_seen，schema 为简单字符串入参。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'mcp_server_id' => McpServer::factory(),
            'name' => $name,
            'description' => fake()->sentence(),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required' => ['query'],
            ],
            'annotations' => null,
            'is_enabled' => true,
            'last_seen_at' => now(),
            'removed_at' => null,
        ];
    }

    /**
     * 标记为已下线工具。
     */
    public function removed(): self
    {
        return $this->state([
            'is_enabled' => false,
            'removed_at' => now(),
        ]);
    }
}
