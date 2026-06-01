<?php

use App\Models\ConversationMessage;

test('消息搜索文本忽略异常翻译 payload', function (): void {
    $message = new ConversationMessage([
        'content' => 'Hello',
        'payload' => [
            'translations' => [
                'zh-CN' => ['text' => '你好'],
                'empty' => ['text' => ''],
                'null' => ['text' => null],
                'array' => ['text' => ['oops']],
                'invalid' => 'oops',
            ],
        ],
    ]);

    expect($message->toSearchableArray()['search_text'])->toBe("Hello\n你好");
});
