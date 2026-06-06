<?php

use App\Actions\Reception\Plan\CollectPlanMcpServersAction;
use App\Models\McpServer;
use App\Models\McpTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('按 tool ID 聚合归属同一 server 的工具白名单', function () {
    $server = McpServer::factory()->create([
        'slug' => 'orders-mcp',
        'name' => '订单 MCP',
        'endpoint_url' => 'https://example.com/mcp',
        'credentials' => ['auth_header_name' => 'X-Auth', 'auth_header_value' => 'secret'],
        'headers' => ['X-Trace' => 'plan-runtime'],
        'timeout_seconds' => 45,
    ]);
    $lookup = McpTool::factory()->for($server, 'server')->create(['name' => 'lookup_order']);
    $cancel = McpTool::factory()->for($server, 'server')->create(['name' => 'cancel_order']);

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle([$lookup->id, $cancel->id]);

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['id'])->toBe((string) $server->id)
        ->and($payload[0]['slug'])->toBe('orders-mcp')
        ->and($payload[0]['endpoint_url'])->toBe('https://example.com/mcp')
        ->and($payload[0]['timeout_seconds'])->toBe(45)
        ->and($payload[0]['credentials'])->toBe(['auth_header_name' => 'X-Auth', 'auth_header_value' => 'secret'])
        ->and($payload[0]['headers'])->toBe(['X-Trace' => 'plan-runtime'])
        ->and($payload[0]['tool_names'])->toEqualCanonicalizing(['lookup_order', 'cancel_order']);
});

test('已下线工具被排除', function () {
    $server = McpServer::factory()->create();
    $available = McpTool::factory()->for($server, 'server')->create(['name' => 'available']);
    $removed = McpTool::factory()->for($server, 'server')->create(['name' => 'removed', 'removed_at' => now()]);

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle([$available->id, $removed->id]);

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['tool_names'])->toBe(['available']);
});

test('endpoint 不完整的服务被跳过', function () {
    $server = McpServer::factory()->create(['endpoint_url' => '']);
    $tool = McpTool::factory()->for($server, 'server')->create(['name' => 'lookup']);

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle([$tool->id]);

    expect($payload)->toBe([]);
});

test('单租户下指定工具会被纳入运行时白名单', function () {
    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->for($server, 'server')->create(['name' => 'lookup']);

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle([$tool->id]);

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['tool_names'])->toBe(['lookup']);
});

test('空 credentials 和 headers 序列化为 JSON 对象', function () {
    $server = McpServer::factory()->create([
        'credentials' => [],
        'headers' => null,
    ]);
    $tool = McpTool::factory()->for($server, 'server')->create();

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle([$tool->id]);

    expect(json_encode($payload[0]['credentials']))->toBe('{}')
        ->and(json_encode($payload[0]['headers']))->toBe('{}');
});

test('空 ID 列表返回空数组', function () {
    $payload = app(CollectPlanMcpServersAction::class)
        ->handle([]);

    expect($payload)->toBe([]);
});
