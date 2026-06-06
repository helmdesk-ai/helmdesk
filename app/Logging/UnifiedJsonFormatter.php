<?php

namespace App\Logging;

use Illuminate\Support\Facades\Context;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * 统一日志 JSON 格式化器，作为 Go + Laravel 共同日志地基的 Laravel 一半。
 *
 * 输出与 Go 侧 slog 对齐的同一套字段（ts / level / service / msg），
 * 并把请求上下文里的关联 ID（request_id / trace_id / context_id）提升到顶层，
 * 便于多实例集中后按单次请求把 Go 与 PHP 的日志串联检索。
 */
class UnifiedJsonFormatter extends JsonFormatter
{
    /** 本服务在统一日志里的 service 标识，与 Go 侧的 "go" 区分。 */
    public const SERVICE = 'laravel';

    /** 从请求上下文提升到日志顶层的关联字段。 */
    private const CONTEXT_KEYS = ['request_id', 'trace_id', 'context_id'];

    /**
     * 开启异常堆栈输出：JsonFormatter 默认会丢掉 trace，但集中检索时最需要的就是堆栈。
     */
    public function __construct()
    {
        parent::__construct(includeStacktraces: true);
    }

    /**
     * 把单条日志渲染成统一 schema 的 JSON 行。
     */
    public function format(LogRecord $record): string
    {
        $payload = [
            // 钉死 UTC：与 app.timezone 解耦，业务时区随便设，日志口径始终 UTC，便于多实例集中检索。
            'ts' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level' => $record->level->getName(),
            'service' => self::SERVICE,
            'msg' => $record->message,
            'channel' => $record->channel,
        ];

        foreach (self::CONTEXT_KEYS as $key) {
            $value = Context::get($key);
            if ($value !== null && $value !== '') {
                $payload[$key] = $value;
            }
        }

        if ($record->context !== []) {
            // 走父类 normalize：把异常展开成 class/message/code/file/trace，并归一化嵌套对象 / DateTime。
            // 否则裸 json_encode 异常对象只会得到 {}，最需要堆栈的日志反而丢掉堆栈。
            $payload['context'] = $this->normalize($record->context);
        }

        // 关联 ID 已提升到顶层，去掉 Laravel 自动塞进 extra 的同名副本，避免重复噪声。
        $extra = array_diff_key($record->extra, array_flip(self::CONTEXT_KEYS));
        if ($extra !== []) {
            $payload['extra'] = $this->normalize($extra);
        }

        return $this->toJson($payload, true)."\n";
    }
}
