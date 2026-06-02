<?php

use App\Actions\AiChat\StopAiAssistantMessageAction;
use App\Models\SystemContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

uses(RefreshDatabase::class);

test('它转发有效后台聊天话题到 Go 运行时', function () {
    $systemContext = SystemContext::factory()->create();
    $topic = "urn:helmdesk:ai-chat:{$systemContext->id}:01KSTOPTEST";

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stop' => Http::response([
            'success' => true,
            'stopped' => true,
        ]),
    ]);

    $result = app(StopAiAssistantMessageAction::class)->handle($systemContext, $topic);

    expect($result)->toBe(['stopped' => true]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'http://go-runtime.test/_helmdesk/internal/ai/chat/stop'
        && $request->header('X-Helmdesk-Bridge-Token')[0] === 'bridge-token'
        && $request['topic'] === $topic);
});

test('它拒绝无效聊天话题', function () {
    $systemContext = SystemContext::factory()->create();

    app(StopAiAssistantMessageAction::class)->handle(
        $systemContext,
        'urn:helmdesk:ai-chat:other:01KSTOPTEST',
    );
})->throws(ValidationException::class);

test('它拒绝格式错误的停止响应来自Go运行时', function () {
    $systemContext = SystemContext::factory()->create();
    $topic = "urn:helmdesk:ai-chat:{$systemContext->id}:01KSTOPTEST";

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/ai/chat/stop' => Http::response([
            'success' => true,
        ]),
    ]);

    app(StopAiAssistantMessageAction::class)->handle($systemContext, $topic);
})->throws(UnprocessableEntityHttpException::class);
