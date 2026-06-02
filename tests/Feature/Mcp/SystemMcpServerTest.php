<?php

use App\Enums\McpSyncStatus;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();

    config()->set('services.go_runtime.base_url', 'http://127.0.0.1:65535');
    config()->set('services.go_runtime.bridge_token', 'test-bridge-token');
});

/**
 * 模拟 Go MCP 桥接：按路径返回固定 success 响应。
 * tools 数组通过参数注入以覆盖增量同步用例。
 *
 * @param  array<int, array<string, mixed>>  $tools
 */
function fakeMcpBridge(array $tools = []): void
{
    Http::fake(function (HttpRequest $request) use ($tools) {
        $url = $request->url();

        if (str_ends_with($url, '/_helmdesk/internal/mcp/servers/validate')) {
            return Http::response([
                'success' => true,
                'supported' => true,
                'code' => 'validate.succeeded',
                'message' => 'ok',
            ]);
        }

        if (str_ends_with($url, '/_helmdesk/internal/mcp/servers/check')) {
            return Http::response([
                'success' => true,
                'supported' => true,
                'code' => 'check.succeeded',
                'message' => 'ok',
            ]);
        }

        if (str_ends_with($url, '/_helmdesk/internal/mcp/servers/list-tools')) {
            return Http::response([
                'success' => true,
                'supported' => true,
                'code' => 'list_tools.succeeded',
                'message' => 'ok',
                'tools' => $tools,
            ]);
        }

        return Http::response(['success' => false, 'message' => 'unexpected url'], 404);
    });
}

function fakeMcpBridgeCheckFailure(string $code, string $message): void
{
    Http::fake(function (HttpRequest $request) use ($code, $message) {
        if (str_ends_with($request->url(), '/check')) {
            return Http::response([
                'success' => false,
                'supported' => true,
                'code' => $code,
                'params' => ['error' => $message],
                'message' => $message,
            ]);
        }

        return Http::response([
            'success' => true,
            'supported' => true,
            'code' => 'list_tools.succeeded',
            'message' => 'ok',
            'tools' => [],
        ]);
    });
}

test('访客用户不能访问 MCP 服务设置', function () {
    $this->get(route('admin.manage.mcp.servers.index'))
        ->assertRedirect('/login');
});

test('非所有者系统成员不能访问 MCP 服务设置', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.manage.mcp.servers.index'))
        ->assertForbidden();
});

test('所有者可以查看空 MCP 服务列表', function () {
    fakeMcpBridge();

    $this->actingAs($this->user)
        ->get(route('admin.manage.mcp.servers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('systemSettings/mcpServers/Index')
            ->has('servers', 0)
            ->has('transport_options'));
});

test('创建 MCP 服务只保存配置，不触发工具同步', function () {
    fakeMcpBridge();

    $this->actingAs($this->user)
        ->post(route('admin.manage.mcp.servers.store'), [
            'name' => 'Shopify MCP',
            'endpoint_url' => 'https://mcp.example.com/v1',
            'transport' => 'streamable_http',
            'auth_header_name' => 'Authorization',
            'auth_header_value' => 'Bearer secret-token',
            'timeout_seconds' => 30,
        ])
        ->assertRedirect();

    $server = McpServer::query()->firstOrFail();

    expect($server->name)->toBe('Shopify MCP');
    expect($server->credentials['auth_header_name'])->toBe('Authorization');
    expect($server->credentials['auth_header_value'])->toBe('Bearer secret-token');
    expect($server->is_active)->toBeFalse();
    expect($server->last_sync_status)->toBe(McpSyncStatus::Pending);
    expect($server->tools()->count())->toBe(0);

    Http::assertNothingSent();
});

test('创建表单校验 endpoint_url 必填且必须为 URL', function () {
    fakeMcpBridge();

    $this->actingAs($this->user)
        ->from(route('admin.manage.mcp.servers.index'))
        ->post(route('admin.manage.mcp.servers.store'), [
            'name' => 'Bad',
            'endpoint_url' => 'not-a-url',
            'transport' => 'streamable_http',
        ])
        ->assertRedirect(route('admin.manage.mcp.servers.index'))
        ->assertSessionHasErrors(['endpoint_url']);
});

test('认证 header name 与 value 必须成对出现', function () {
    fakeMcpBridge();

    // 模拟真实前端：表单字段始终随提交一并送出（空字符串），中间件后端再转 null。
    // 用户填了 name 没填 value 的场景下，规则应识别为半配置并报错。
    $this->actingAs($this->user)
        ->from(route('admin.manage.mcp.servers.index'))
        ->post(route('admin.manage.mcp.servers.store'), [
            'name' => 'Half Auth',
            'endpoint_url' => 'https://mcp.example.com/v1',
            'transport' => 'streamable_http',
            'auth_header_name' => 'Authorization',
            'auth_header_value' => '',
        ])
        ->assertRedirect(route('admin.manage.mcp.servers.index'))
        ->assertSessionHasErrors(['auth_header_value']);
});

test('支持自定义认证 header 名（如 X-Api-Key）', function () {
    fakeMcpBridge();

    $this->actingAs($this->user)
        ->post(route('admin.manage.mcp.servers.store'), [
            'name' => 'Stripe-like MCP',
            'endpoint_url' => 'https://mcp.example.com/api',
            'transport' => 'streamable_http',
            'auth_header_name' => 'X-Api-Key',
            'auth_header_value' => 'sk_live_xxx',
        ])
        ->assertRedirect();

    $server = McpServer::query()->firstOrFail();
    expect($server->credentials['auth_header_name'])->toBe('X-Api-Key');
    expect($server->credentials['auth_header_value'])->toBe('sk_live_xxx');
});

test('更新表单未传认证字段时保留旧凭据', function () {
    fakeMcpBridge();

    $server = McpServer::factory()
        ->withBearerToken('original-token')
        ->create();

    $this->actingAs($this->user)
        ->put(route('admin.manage.mcp.servers.update', ['server' => $server->slug,
        ]), [
            'name' => 'Renamed',
            'endpoint_url' => 'https://mcp.example.com/v2',
            'timeout_seconds' => 45,
            // 未传 auth_header_name / auth_header_value
        ])
        ->assertRedirect();

    $server->refresh();
    expect($server->name)->toBe('Renamed');
    expect($server->endpoint_url)->toBe('https://mcp.example.com/v2');
    expect($server->credentials['auth_header_name'])->toBe('Authorization');
    expect($server->credentials['auth_header_value'])->toBe('Bearer original-token');
    expect($server->timeout_seconds)->toBe(45);

    Http::assertNothingSent();
});

test('clear_auth_credentials = true 时会清掉凭据', function () {
    fakeMcpBridge();

    $server = McpServer::factory()
        ->withBearerToken('original-token')
        ->create();

    $this->actingAs($this->user)
        ->put(route('admin.manage.mcp.servers.update', ['server' => $server->slug,
        ]), [
            'name' => $server->name,
            'endpoint_url' => $server->endpoint_url,
            'clear_auth_credentials' => true,
        ])
        ->assertRedirect();

    expect($server->fresh()->credentials)->toBeNull();
});

test('启用服务前必须有 endpoint URL', function () {
    fakeMcpBridge();

    $server = McpServer::factory()
        ->create([
            'endpoint_url' => '',
            'is_active' => false,
        ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.mcp.servers.index'))
        ->withHeader('X-Inertia', 'true')
        ->put(route('admin.manage.mcp.servers.toggle', ['server' => $server->slug,
        ]))
        ->assertRedirect(route('admin.manage.mcp.servers.index'))
        ->assertSessionHasErrors(['toast']);

    expect($server->fresh()->is_active)->toBeFalse();
});

test('正常启用与停用 MCP 服务', function () {
    fakeMcpBridge();

    $server = McpServer::factory()->create();

    $this->actingAs($this->user)
        ->put(route('admin.manage.mcp.servers.toggle', ['server' => $server->slug,
        ]))
        ->assertRedirect();

    expect($server->fresh()->is_active)->toBeTrue();

    $this->actingAs($this->user)
        ->put(route('admin.manage.mcp.servers.toggle', ['server' => $server->slug,
        ]))
        ->assertRedirect();

    expect($server->fresh()->is_active)->toBeFalse();
});

test('Check 端点成功时返回 JSON 结果', function () {
    fakeMcpBridge();

    $server = McpServer::factory()->create();

    $this->actingAs($this->user)
        ->post(route('admin.manage.mcp.servers.check', ['server' => $server->slug,
        ]))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => '连接正常。',
        ]);
});

test('Check 端点使用当前表单配置而不是已保存配置', function () {
    fakeMcpBridge();

    $server = McpServer::factory()
        ->withBearerToken('old-token')
        ->create([
            'endpoint_url' => 'https://old.example.com/mcp',
            'timeout_seconds' => 30,
        ]);

    $this->actingAs($this->user)
        ->post(route('admin.manage.mcp.servers.check', ['server' => $server->slug,
        ]), [
            'name' => $server->name,
            'endpoint_url' => 'https://new.example.com/mcp',
            'auth_header_name' => 'Authorization',
            'auth_header_value' => 'Bearer new-token',
            'timeout_seconds' => 45,
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => '连接正常。',
        ]);

    expect($server->fresh()->endpoint_url)->toBe('https://old.example.com/mcp');

    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/mcp/servers/check')
        && data_get($request->data(), 'server.endpoint_url') === 'https://new.example.com/mcp'
        && data_get($request->data(), 'server.credentials.auth_header_value') === 'Bearer new-token'
        && data_get($request->data(), 'server.timeout_seconds') === 45);
});

test('Check 端点支持测试尚未保存的新配置', function () {
    fakeMcpBridge();

    $this->actingAs($this->user)
        ->post(route('admin.manage.mcp.servers.check-unsaved', []), [
            'name' => 'Unsaved MCP',
            'endpoint_url' => 'https://unsaved.example.com/mcp',
            'transport' => 'streamable_http',
            'auth_header_name' => 'Authorization',
            'auth_header_value' => 'Bearer unsaved-token',
            'timeout_seconds' => 20,
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => '连接正常。',
        ]);

    expect(McpServer::query()->count())->toBe(0);

    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/mcp/servers/check')
        && data_get($request->data(), 'server.name') === 'Unsaved MCP'
        && data_get($request->data(), 'server.endpoint_url') === 'https://unsaved.example.com/mcp'
        && data_get($request->data(), 'server.credentials.auth_header_value') === 'Bearer unsaved-token'
        && data_get($request->data(), 'server.timeout_seconds') === 20);
});

test('Check 失败时返回 JSON 失败原因', function () {
    fakeMcpBridgeCheckFailure('check.failed', 'connection refused');

    $server = McpServer::factory()->create();

    $this->actingAs($this->user)
        ->from(route('admin.manage.mcp.servers.index'))
        ->post(route('admin.manage.mcp.servers.check', ['server' => $server->slug,
        ]))
        ->assertOk()
        ->assertJson([
            'success' => false,
            'message' => 'MCP 服务连接失败：connection refused',
        ]);
});

test('Sync 新增并下线工具', function () {
    fakeMcpBridge([
        ['name' => 'new_tool', 'description' => 'Brand new tool'],
    ]);

    $server = McpServer::factory()->create();
    McpTool::factory()->for($server, 'server')->create([
        'name' => 'old_tool',
        'is_enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.manage.mcp.servers.sync', ['server' => $server->slug,
        ]))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => '同步完成，共 1 个工具（新增 1、下线 1）。',
            'total' => 1,
            'added' => 1,
            'removed' => 1,
        ]);

    $newTool = $server->tools()->where('name', 'new_tool')->firstOrFail();
    expect($newTool->is_enabled)->toBeTrue();
    expect($newTool->removed_at)->toBeNull();

    $oldTool = $server->tools()->where('name', 'old_tool')->firstOrFail();
    expect($oldTool->is_enabled)->toBeFalse();
    expect($oldTool->removed_at)->not->toBeNull();

    expect($server->fresh()->last_sync_status)->toBe(McpSyncStatus::Success);
});

test('禁用并重新启用工具', function () {
    fakeMcpBridge();

    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->for($server, 'server')->create([
        'name' => 'tool_a',
        'is_enabled' => true,
        'removed_at' => null,
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.mcp.tools.toggle', ['server' => $server->slug,
            'tool' => $tool->id,
        ]))
        ->assertRedirect();

    expect($tool->fresh()->is_enabled)->toBeFalse();

    $this->actingAs($this->user)
        ->put(route('admin.manage.mcp.tools.toggle', ['server' => $server->slug,
            'tool' => $tool->id,
        ]))
        ->assertRedirect();

    expect($tool->fresh()->is_enabled)->toBeTrue();
});

test('已下线工具不能再启用', function () {
    fakeMcpBridge();

    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->removed()->for($server, 'server')->create([
        'name' => 'gone',
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.mcp.servers.index'))
        ->withHeader('X-Inertia', 'true')
        ->put(route('admin.manage.mcp.tools.toggle', ['server' => $server->slug,
            'tool' => $tool->id,
        ]))
        ->assertRedirect(route('admin.manage.mcp.servers.index'))
        ->assertSessionHasErrors(['toast']);

    expect($tool->fresh()->is_enabled)->toBeFalse();
});

test('删除 MCP 服务会一并清理工具记录', function () {
    fakeMcpBridge();

    $server = McpServer::factory()->create();
    McpTool::factory()->for($server, 'server')->count(3)->create();

    $this->actingAs($this->user)
        ->delete(route('admin.manage.mcp.servers.destroy', ['server' => $server->slug,
        ]))
        ->assertRedirect();

    expect(McpServer::query()->find($server->id))->toBeNull();
    expect(McpTool::query()->where('mcp_server_id', $server->id)->count())->toBe(0);
});

test('单租户下管理员可以看到全部 MCP 服务', function () {
    fakeMcpBridge();

    McpServer::factory()->create();
    McpServer::factory()->create();

    $this->actingAs($this->user)
        ->get(route('admin.manage.mcp.servers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('servers', 2));
});
