<?php

use App\Actions\Reception\CaptureWebConversationContextAction;
use App\Models\Conversation;
use App\Services\Reception\UserAgentParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const CHROME_MAC_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

test('UA 解析桌面 Chrome 浏览器与平台', function () {
    $parsed = app(UserAgentParser::class)->parse(CHROME_MAC_UA);

    expect($parsed['browser'])->toBe('Chrome')
        ->and($parsed['browser_version'])->toStartWith('120')
        ->and($parsed['platform'])->toBe('OS X')
        ->and($parsed['device_type'])->toBe('desktop');
});

test('UA 解析移动端 iPhone 设备类型', function () {
    $parsed = app(UserAgentParser::class)->parse(IPHONE_UA);

    expect($parsed['device_type'])->toBe('mobile')
        ->and($parsed['platform'])->toBe('iOS');
});

test('建会话时由 UA 派生浏览器与设备类型落入渠道上下文', function () {
    $conversation = Conversation::factory()->create();

    app(CaptureWebConversationContextAction::class)->handle(
        $conversation,
        [
            'current_url' => 'https://shop.example.com/pricing',
            'user_agent' => CHROME_MAC_UA,
            'ip_address' => '8.8.8.8',
        ],
        [],
        true,
    );

    $context = $conversation->fresh()->channel_context;

    expect($context->browser)->toBe('Chrome')
        ->and($context->platform)->toBe('OS X')
        ->and($context->device_type)->toBe('desktop')
        ->and($context->user_agent)->toBe(CHROME_MAC_UA);
});
