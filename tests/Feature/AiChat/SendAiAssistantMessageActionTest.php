<?php

use App\Actions\AiChat\SendAiAssistantMessageAction;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

uses(RefreshDatabase::class);

function createAiChatTestProvider(SystemContext $systemContext, array $attributes = []): AiProvider
{
    return AiProvider::query()->create(array_merge([
        'brand' => 'custom-openai',
        'slug' => 'test-provider-ai-chat',
        'name' => 'Test Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ], $attributes));
}

function createAiChatTestModel(AiProvider $provider, array $attributes = []): AiModel
{
    return AiModel::query()->create(array_merge([
        'ai_provider_id' => $provider->id,
        'name' => 'Test Model',
        'model_id' => 'gpt-4o',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ], $attributes));
}

test('它携带最近二十条历史消息到Go运行时', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => true,
            'topic' => 'urn:helmdesk:ai-chat:test',
        ], 202),
    ]);

    $history = collect(range(1, 25))
        ->map(fn (int $index): array => [
            'role' => $index % 2 === 0 ? 'assistant' : 'user',
            'content' => str_repeat((string) ($index % 10), 8100),
        ])
        ->all();
    $prompt = str_repeat('p', 9000);

    app(SendAiAssistantMessageAction::class)->handle($systemContext, $prompt, $history, $model->id);

    Http::assertSent(function ($request) use ($history, $prompt): bool {
        $messages = $request['messages'];

        return $request->method() === 'POST'
            && $request->url() === 'http://go-runtime.test/_helmdesk/internal/ai/chat/stream'
            && count($messages) === 21
            && $messages[0]['role'] === $history[5]['role']
            && $messages[0]['content'] === $history[5]['content']
            && mb_strlen($messages[0]['content']) === 8100
            && $messages[20]['role'] === 'user'
            && $messages[20]['content'] === $prompt;
    });
});

test('它拒绝过大的聊天历史来自系统路由', function () {
    $user = User::factory()->create(['is_super_admin' => true]);
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    $this->actingAs($user, 'admin')
        ->postJson(route('admin.ai-chat.messages.store'), [
            'prompt' => 'hello',
            'model_id' => $model->id,
            'history' => collect(range(1, 21))
                ->map(fn (): array => ['role' => 'user', 'content' => 'hello'])
                ->all(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['history']);
});

test('它拒绝空提示词在触及桥接前', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    Http::fake();

    expect(fn () => app(SendAiAssistantMessageAction::class)->handle($systemContext, "   \n\t", [], $model->id))
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('它转发用户提示词到Go运行时', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => true,
            'topic' => 'urn:helmdesk:ai-chat:test',
        ], 202),
    ]);

    $prompt = str_repeat('a', 9000);

    app(SendAiAssistantMessageAction::class)->handle($systemContext, $prompt, [], $model->id);

    Http::assertSent(fn ($request): bool => ($request['messages'][0]['content'] ?? null) === $prompt);
});

test('它暴露已净化错误和返回422当桥接失败时', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => false,
            // 模拟上游把 API key 直接回吐到 error 字段——必须被脱敏后才能展示给浏览器。
            'error' => 'authentication failed for sk-abcdefghijklmnopqrst123456',
        ], 422),
    ]);

    try {
        app(SendAiAssistantMessageAction::class)->handle($systemContext, 'hello', [], $model->id);
        expect(true)->toBeFalse('Expected UnprocessableEntityHttpException');
    } catch (UnprocessableEntityHttpException $exception) {
        expect($exception->getMessage())
            ->not->toContain('sk-abcdefghijklmnopqrst123456')
            ->and($exception->getMessage())->toContain('[redacted-key]');
    }
});

test('系统路由是限流到合理数字的请求每分钟', function () {
    $user = User::factory()->create(['is_super_admin' => true]);
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => false,
            'error' => 'no model resolved',
        ], 422),
    ]);

    // 30 是当前 throttle:30,1 的阈值；第 31 次必须被 RateLimiter 截下来。
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($user, 'admin')
            ->postJson(route('admin.ai-chat.messages.store'), [
                'prompt' => 'hi',
                'model_id' => $model->id,
            ]);
    }

    $this->actingAs($user, 'admin')
        ->postJson(route('admin.ai-chat.messages.store'), [
            'prompt' => 'hi',
            'model_id' => $model->id,
        ])
        ->assertStatus(429);
});

test('它拒绝聊天请求且没有已选择的模型', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    createAiChatTestModel($provider);

    Http::fake();

    expect(fn () => app(SendAiAssistantMessageAction::class)->handle($systemContext, 'hello'))
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('它拒绝不可用已选择模型', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider, ['is_active' => false]);

    Http::fake();

    expect(fn () => app(SendAiAssistantMessageAction::class)->handle($systemContext, 'hello', [], $model->id))
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('它拒绝无效历史角色在触及桥接前', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    Http::fake();

    expect(fn () => app(SendAiAssistantMessageAction::class)->handle(
        $systemContext,
        'hello',
        [['role' => 'bot', 'content' => 'legacy alias']],
        $model->id,
    ))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('它转发已启用的 MCP 服务和工具白名单到 Go 桥接', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    // 期望被推送的服务：is_active = true 且至少有 1 个 is_enabled 工具。
    $activeServer = McpServer::factory()
        ->withBearerToken('mcp-token')
        ->create([
            'is_active' => true,
            'endpoint_url' => 'https://mcp.example.com/active',
            'headers' => ['X-Context' => 'wsx'],
            'timeout_seconds' => 45,
            'sort_order' => 1,
        ]);
    McpTool::factory()->for($activeServer, 'server')->create(['name' => 'search_orders', 'is_enabled' => true]);
    McpTool::factory()->for($activeServer, 'server')->create(['name' => 'cancel_order', 'is_enabled' => true]);
    // 已下线工具不应进入白名单。
    McpTool::factory()->removed()->for($activeServer, 'server')->create(['name' => 'legacy_op']);
    // is_enabled = false 工具不应进入白名单。
    McpTool::factory()->for($activeServer, 'server')->create(['name' => 'paused_op', 'is_enabled' => false]);

    // 期望被跳过的服务：is_active = false。
    $inactiveServer = McpServer::factory()->create([
        'is_active' => false,
        'endpoint_url' => 'https://mcp.example.com/inactive',
    ]);
    McpTool::factory()->for($inactiveServer, 'server')->create(['name' => 'noop']);

    // 期望被跳过的服务：is_active = true 但没有可用工具。
    $emptyServer = McpServer::factory()->create([
        'is_active' => true,
        'endpoint_url' => 'https://mcp.example.com/empty',
        'sort_order' => 0,
    ]);
    McpTool::factory()->removed()->for($emptyServer, 'server')->create(['name' => 'gone']);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => true,
            'topic' => 'urn:helmdesk:ai-chat:test',
        ], 202),
    ]);

    app(SendAiAssistantMessageAction::class)->handle($systemContext, 'hello', [], $model->id);

    Http::assertSent(function ($request) use ($activeServer): bool {
        $mcpServers = $request['mcp_servers'] ?? null;
        if (! is_array($mcpServers) || count($mcpServers) !== 1) {
            return false;
        }

        $server = $mcpServers[0];

        return ($server['id'] ?? null) === (string) $activeServer->id
            && ($server['slug'] ?? null) === (string) $activeServer->slug
            && ($server['endpoint_url'] ?? null) === 'https://mcp.example.com/active'
            && ($server['transport'] ?? null) === 'streamable_http'
            && ($server['timeout_seconds'] ?? null) === 45
            && ($server['credentials']['auth_header_value'] ?? null) === 'Bearer mcp-token'
            && ($server['headers']['X-Context'] ?? null) === 'wsx'
            && is_array($server['tool_names'] ?? null)
            && count($server['tool_names']) === 2
            && in_array('search_orders', $server['tool_names'], true)
            && in_array('cancel_order', $server['tool_names'], true);
    });
});

test('它在系统没有可用 MCP 工具时下发空数组到 Go 桥接', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => true,
            'topic' => 'urn:helmdesk:ai-chat:test',
        ], 202),
    ]);

    app(SendAiAssistantMessageAction::class)->handle($systemContext, 'hello', [], $model->id);

    Http::assertSent(fn ($request): bool => ($request['mcp_servers'] ?? null) === []);
});

test('它把知识库列表下发给 Go 桥接', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    $kb = KnowledgeBase::factory()->create([
        'name' => '客服 FAQ',
        'description' => '常见问题与回复模板',
    ]);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => true,
            'topic' => 'urn:helmdesk:ai-chat:test',
        ], 202),
    ]);

    app(SendAiAssistantMessageAction::class)->handle($systemContext, 'hello', [], $model->id);

    Http::assertSent(function ($request) use ($kb): bool {
        $bases = $request['knowledge_bases'] ?? null;
        if (! is_array($bases) || count($bases) !== 1) {
            return false;
        }

        return ($bases[0]['id'] ?? null) === (string) $kb->id
            && ($bases[0]['name'] ?? null) === '客服 FAQ'
            && ($bases[0]['description'] ?? null) === '常见问题与回复模板';
    });
});

test('它把 MCP 空凭据和空请求头序列化为 JSON 对象', function () {
    $systemContext = SystemContext::factory()->create();
    $provider = createAiChatTestProvider($systemContext);
    $model = createAiChatTestModel($provider);

    $server = McpServer::factory()->create([
        'is_active' => true,
        'endpoint_url' => 'https://mcp.example.com/no-auth',
        'credentials' => null,
        'headers' => null,
    ]);
    McpTool::factory()->for($server, 'server')->create(['name' => 'lookup', 'is_enabled' => true]);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stream' => Http::response([
            'success' => true,
            'topic' => 'urn:helmdesk:ai-chat:test',
        ], 202),
    ]);

    app(SendAiAssistantMessageAction::class)->handle($systemContext, 'hello', [], $model->id);

    Http::assertSent(function ($request): bool {
        $payload = json_decode($request->body());
        $server = $payload?->mcp_servers[0] ?? null;

        return $server !== null
            && $server->credentials instanceof stdClass
            && get_object_vars($server->credentials) === []
            && $server->headers instanceof stdClass
            && get_object_vars($server->headers) === [];
    });
});
