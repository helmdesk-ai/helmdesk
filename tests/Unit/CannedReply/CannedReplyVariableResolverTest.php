<?php

use App\Data\CannedReply\CannedReplyRenderContextData;
use App\Services\CannedReply\CannedReplyVariableResolver;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->resolver = new CannedReplyVariableResolver;
    $this->context = new CannedReplyRenderContextData(
        workspace_name: 'Helmdesk',
        teammate_name: '张客服',
        contact_name: '李四',
        contact_email: 'lisi@example.com',
        contact_primary_phone: '138-0000-0000',
        conversation_id: '01HXYZ',
        conversation_subject: '退款咨询',
    );
});

test('解析所有静态命名空间变量', function () {
    $template = '你好 {{contact.name}}（{{contact.email}}），我是 {{teammate.name}}，'
        .'代表 {{workspace.name}} 处理你的会话 {{conversation.subject}} (id: {{conversation.id}})。';

    $result = $this->resolver->render($template, $this->context);

    expect($result['content'])->toBe(
        '你好 李四（lisi@example.com），我是 张客服，代表 Helmdesk 处理你的会话 退款咨询 (id: 01HXYZ)。'
    );
    expect($result['warnings'])->toBe([]);
});

test('忽略未知命名空间，原样保留', function () {
    $template = '请联系 {{customer.name}} 或 {{contact.name}}。';

    $result = $this->resolver->render($template, $this->context);

    expect($result['content'])->toBe('请联系 {{customer.name}} 或 李四。');
});

test('AI 命名空间在未启用时原样保留并产生 warning', function () {
    $template = 'AI 推荐：{{ai.suggested_reply}}';

    $result = $this->resolver->render($template, $this->context);

    expect($result['content'])->toBe('AI 推荐：{{ai.suggested_reply}}');
    expect($result['warnings'])->toHaveCount(1);
    expect($result['warnings'][0])->toContain('{{ai.suggested_reply}}');
});

test('缺值字段保留原文并产生 warning', function () {
    $emptyContext = new CannedReplyRenderContextData(
        workspace_name: 'Helmdesk',
        teammate_name: '张客服',
        contact_name: null,
        contact_email: null,
        contact_primary_phone: null,
        conversation_id: null,
        conversation_subject: null,
    );

    $result = $this->resolver->render('你好 {{contact.name}}，主题：{{conversation.subject}}', $emptyContext);

    expect($result['content'])->toBe('你好 {{contact.name}}，主题：{{conversation.subject}}');
    expect($result['warnings'])->toHaveCount(2);
});

test('extractTokens 返回模版中实际出现的 token', function () {
    $template = '{{contact.name}} {{contact.email}} {{contact.name}} 静态文本';

    $tokens = $this->resolver->extractTokens($template);

    expect($tokens)->toBe(['{{contact.name}}', '{{contact.email}}']);
});

test('availableTokens 返回 v1 支持的全部静态变量列表', function () {
    $tokens = $this->resolver->availableTokens();

    $tokenStrings = array_column($tokens, 'token');
    expect($tokenStrings)->toContain('{{contact.name}}');
    expect($tokenStrings)->toContain('{{contact.email}}');
    expect($tokenStrings)->toContain('{{contact.primary_phone}}');
    expect($tokenStrings)->toContain('{{conversation.subject}}');
    expect($tokenStrings)->toContain('{{conversation.id}}');
    expect($tokenStrings)->toContain('{{teammate.name}}');
    expect($tokenStrings)->toContain('{{workspace.name}}');
    expect($tokenStrings)->not->toContain('{{ai.suggested_reply}}');
});
