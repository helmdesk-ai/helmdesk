<?php

use App\Http\Middleware\AssignRequestContext;
use App\Logging\UnifiedJsonFormatter;
use App\Logging\UnifiedTextFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Monolog\DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

it('把日志渲染成与 Go 对齐的统一 JSON schema', function () {
    Context::add('request_id', 'req-1');
    Context::add('trace_id', 'trace-1');

    $record = new LogRecord(
        datetime: new DateTimeImmutable(true),
        channel: 'testing',
        level: Level::Info,
        message: '测试消息',
        context: ['k' => 'v'],
    );

    $json = json_decode((new UnifiedJsonFormatter)->format($record), true);

    expect($json['service'])->toBe('laravel')
        ->and($json['msg'])->toBe('测试消息')
        ->and($json['level'])->toBe('INFO')
        ->and($json['channel'])->toBe('testing')
        ->and($json['request_id'])->toBe('req-1')
        ->and($json['trace_id'])->toBe('trace-1')
        ->and($json)->toHaveKey('ts')
        ->and($json['context'])->toBe(['k' => 'v']);
});

it('把日志渲染成终端友好的统一文本格式', function () {
    Context::add('request_id', 'req-text');
    Context::add('trace_id', 'trace-text');

    $record = new LogRecord(
        datetime: new DateTimeImmutable(true),
        channel: 'testing',
        level: Level::Info,
        message: '测试 文本',
        context: ['k' => 'v'],
    );

    $line = (new UnifiedTextFormatter(false))->format($record);

    expect($line)->toMatch('/^ts=\\S+ level=INFO service=laravel channel=testing /')
        ->and($line)->toContain('request_id=req-text')
        ->and($line)->toContain('trace_id=trace-text')
        ->and($line)->toContain('context={"k":"v"}')
        ->and($line)->toEndWith('msg="测试 文本"'."\n");
});

it('文本日志按开发态策略输出 ANSI 颜色', function () {
    $record = new LogRecord(
        datetime: new DateTimeImmutable(true),
        channel: 'testing',
        level: Level::Error,
        message: 'colored',
    );

    $colored = (new UnifiedTextFormatter(true))->format($record);
    $plain = (new UnifiedTextFormatter(false))->format($record);

    expect($colored)->toContain("level=\033[31mERROR\033[0m")
        ->and($plain)->toContain('level=ERROR')
        ->and($plain)->not->toContain("\033[31m");
});

it('文本日志把 warning 级别对齐到 Go slog 的 WARN', function () {
    $record = new LogRecord(
        datetime: new DateTimeImmutable(true),
        channel: 'testing',
        level: Level::Warning,
        message: 'warn-check',
    );

    $line = (new UnifiedTextFormatter(false))->format($record);

    expect($line)->toContain('level=WARN')
        ->and($line)->not->toContain('level=WARNING');
});

it('文本日志把控制字符转义成可读形式，避免裸字符污染终端', function () {
    $record = new LogRecord(
        datetime: new DateTimeImmutable(true),
        channel: 'testing',
        level: Level::Info,
        message: "tab\there\x01end",
    );

    $line = (new UnifiedTextFormatter(false))->format($record);

    expect($line)->toEndWith('msg="tab\there\x01end"'."\n")
        ->and($line)->not->toContain("\t")
        ->and($line)->not->toContain("\x01");
});

it('把 context 里的异常展开成可检索的结构（保留堆栈）', function () {
    // 裸 json_encode 异常对象只会得到 {}，这里验证 formatter 经父类 normalize 保留了 class/message/trace。
    $record = new LogRecord(
        datetime: new DateTimeImmutable(true),
        channel: 'testing',
        level: Level::Error,
        message: 'boom',
        context: ['exception' => new RuntimeException('炸了', 42)],
    );

    $json = json_decode((new UnifiedJsonFormatter)->format($record), true);

    expect($json['context']['exception']['class'])->toBe(RuntimeException::class)
        ->and($json['context']['exception']['message'])->toBe('炸了')
        ->and($json['context']['exception']['code'])->toBe(42)
        ->and($json['context']['exception'])->toHaveKey('file')
        ->and($json['context']['exception']['trace'])->toBeArray()->not->toBeEmpty();
});

it('无论输入时区如何，ts 一律输出为 UTC', function () {
    // 用东八区时间构造记录，验证 formatter 钉死 UTC、不跟随 app.timezone 或输入时区。
    $record = new LogRecord(
        datetime: new \DateTimeImmutable('2026-05-30 14:00:00', new DateTimeZone('Asia/Shanghai')),
        channel: 'testing',
        level: Level::Info,
        message: 'tz-check',
    );

    $json = json_decode((new UnifiedJsonFormatter)->format($record), true);

    // 东八 14:00 == UTC 06:00，偏移量应为 +00:00。
    expect($json['ts'])->toStartWith('2026-05-30T06:00:00')
        ->and($json['ts'])->toEndWith('+00:00');
});

it('未设置关联 ID 时不输出空字段', function () {
    $record = new LogRecord(
        datetime: new DateTimeImmutable(true),
        channel: 'testing',
        level: Level::Warning,
        message: 'no-context',
    );

    $json = json_decode((new UnifiedJsonFormatter)->format($record), true);

    expect($json)->not->toHaveKey('request_id')
        ->and($json)->not->toHaveKey('trace_id')
        ->and($json)->not->toHaveKey('context')
        ->and($json['level'])->toBe('WARNING');
});

it('中间件生成 request_id 并默认让 trace_id 与之一致', function () {
    $request = Request::create('/x', 'GET');

    $response = (new AssignRequestContext)->handle($request, fn () => new Response('ok'));

    $requestId = Context::get('request_id');

    expect($requestId)->not->toBeNull()
        ->and(Context::get('trace_id'))->toBe($requestId)
        ->and($response->headers->get('X-Request-Id'))->toBe($requestId);
});

it('中间件复用上游传入的 trace 头', function () {
    $request = Request::create('/x', 'GET');
    $request->headers->set('X-Helmdesk-Trace-Id', 'upstream-trace');

    (new AssignRequestContext)->handle($request, fn () => new Response('ok'));

    expect(Context::get('trace_id'))->toBe('upstream-trace');
});
