<?php

use App\Actions\Reception\Plan\CollectPlanMcpServersAction;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create(['name' => 'Test Workspace']);
});

test('按 tool ID 聚合归属同一 server 的工具白名单', function () {
    $server = McpServer::factory()->for($this->workspace)->active()->create([
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
        ->handle($this->workspace, [$lookup->id, $cancel->id]);

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['id'])->toBe((string) $server->id)
        ->and($payload[0]['slug'])->toBe('orders-mcp')
        ->and($payload[0]['endpoint_url'])->toBe('https://example.com/mcp')
        ->and($payload[0]['timeout_seconds'])->toBe(45)
        ->and($payload[0]['credentials'])->toBe(['auth_header_name' => 'X-Auth', 'auth_header_value' => 'secret'])
        ->and($payload[0]['headers'])->toBe(['X-Trace' => 'plan-runtime'])
        ->and($payload[0]['tool_names'])->toEqualCanonicalizing(['lookup_order', 'cancel_order']);
});

test('已禁用 / 已下线工具被排除', function () {
    $server = McpServer::factory()->for($this->workspace)->active()->create();
    $enabled = McpTool::factory()->for($server, 'server')->create(['name' => 'enabled', 'is_enabled' => true]);
    $disabled = McpTool::factory()->for($server, 'server')->create(['name' => 'disabled', 'is_enabled' => false]);
    $removed = McpTool::factory()->for($server, 'server')->create(['name' => 'removed', 'removed_at' => now()]);

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle($this->workspace, [$enabled->id, $disabled->id, $removed->id]);

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['tool_names'])->toBe(['enabled']);
});

test('停用 server 上的工具整台跳过', function () {
    $server = McpServer::factory()->for($this->workspace)->create(['is_active' => false]);
    $tool = McpTool::factory()->for($server, 'server')->create(['name' => 'lookup']);

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle($this->workspace, [$tool->id]);

    expect($payload)->toBe([]);
});

test('跨工作区的工具不会被混入', function () {
    $foreignWorkspace = Workspace::factory()->create(['name' => 'Foreign']);
    $foreignServer = McpServer::factory()->for($foreignWorkspace)->active()->create();
    $foreignTool = McpTool::factory()->for($foreignServer, 'server')->create();

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle($this->workspace, [$foreignTool->id]);

    expect($payload)->toBe([]);
});

test('空 credentials / headers 序列化为 JSON 对象保证 Go map 解码兼容', function () {
    $server = McpServer::factory()->for($this->workspace)->active()->create([
        'credentials' => [],
        'headers' => null,
    ]);
    $tool = McpTool::factory()->for($server, 'server')->create();

    $payload = app(CollectPlanMcpServersAction::class)
        ->handle($this->workspace, [$tool->id]);

    expect(json_encode($payload[0]['credentials']))->toBe('{}')
        ->and(json_encode($payload[0]['headers']))->toBe('{}');
});

test('空 ID 列表返回空数组', function () {
    $payload = app(CollectPlanMcpServersAction::class)
        ->handle($this->workspace, []);

    expect($payload)->toBe([]);
});
