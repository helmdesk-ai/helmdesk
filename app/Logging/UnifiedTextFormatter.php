<?php

namespace App\Logging;

use Illuminate\Support\Facades\Context;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * 统一日志终端文本格式化器，用于本地开发时把统一字段渲染成更易读的单行文本。
 */
class UnifiedTextFormatter extends JsonFormatter
{
    /** 从请求上下文提升到日志行上的关联字段。 */
    private const CONTEXT_KEYS = ['request_id', 'trace_id', 'tenant_id'];

    /** 日志级别颜色，仅用于开发态 TTY 文本输出。 */
    private const LEVEL_COLORS = [
        'DEBUG' => "\033[36m",
        'INFO' => "\033[32m",
        'NOTICE' => "\033[32m",
        'WARN' => "\033[33m",
        'ERROR' => "\033[31m",
        'CRITICAL' => "\033[31m",
        'ALERT' => "\033[31m",
        'EMERGENCY' => "\033[31m",
    ];

    /** 是否输出 ANSI 颜色。 */
    private bool $color;

    /**
     * 开启异常堆栈输出，保持与 JSON formatter 相同的排障信息量。
     */
    public function __construct(?bool $color = null)
    {
        parent::__construct(includeStacktraces: true);

        $this->color = $color ?? $this->shouldColorize();
    }

    /**
     * 把单条日志渲染成终端友好的 key=value 文本行。
     */
    public function format(LogRecord $record): string
    {
        $parts = [
            'ts='.$record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'level='.$this->levelText($record),
            'service='.UnifiedJsonFormatter::SERVICE,
            'channel='.$this->quote($record->channel),
        ];

        foreach (self::CONTEXT_KEYS as $key) {
            $value = Context::get($key);
            if ($value !== null && $value !== '') {
                $parts[] = $key.'='.$this->quote((string) $value);
            }
        }

        if ($record->context !== []) {
            $parts[] = 'context='.$this->encode($this->normalize($record->context));
        }

        $extra = array_diff_key($record->extra, array_flip(self::CONTEXT_KEYS));
        if ($extra !== []) {
            $parts[] = 'extra='.$this->encode($this->normalize($extra));
        }

        $parts[] = 'msg='.$this->quote($record->message);

        return implode(' ', $parts)."\n";
    }

    /**
     * 编码结构化字段，避免文本日志丢失 context / extra 的嵌套信息。
     */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 按 shell 风格包裹含空白的值，让终端里仍能快速扫读。
     */
    private function quote(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (! preg_match('/[\s="\\\\[:cntrl:]]/', $value)) {
            return $value;
        }

        return '"'.$this->escapeQuoted($value).'"';
    }

    /**
     * 转义引号内的内容，与 Go 侧 strconv.Quote 口径对齐：
     * 反斜杠/引号转义，控制字符转成 \n \r \t 或 \xHH，避免裸控制字符污染终端、破坏 logfmt 解析。
     */
    private function escapeQuoted(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\"'], $value);

        return preg_replace_callback('/[[:cntrl:]]/', static function (array $match): string {
            return match ($match[0]) {
                "\n" => '\n',
                "\r" => '\r',
                "\t" => '\t',
                default => sprintf('\x%02x', ord($match[0])),
            };
        }, $escaped);
    }

    /**
     * 返回与 Go slog 文本输出对齐的日志级别名称。
     */
    private function levelText(LogRecord $record): string
    {
        $level = match ($record->level->getName()) {
            'WARNING' => 'WARN',
            default => $record->level->getName(),
        };

        if (! $this->color) {
            return $level;
        }

        $color = self::LEVEL_COLORS[$level] ?? null;
        if ($color === null) {
            return $level;
        }

        return $color.$level."\033[0m";
    }

    /**
     * 判断开发文本日志是否输出 ANSI 颜色。
     *
     * 终端不会自动识别 key=value 文本并上色；默认 auto 只在 stderr 是 TTY 时输出颜色，
     * 写入文件、管道和采集器时保持纯文本。
     */
    private function shouldColorize(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        $mode = strtolower(trim((string) getenv('LOG_COLOR')));

        return match ($mode) {
            'always', 'true', '1', 'yes' => true,
            'never', 'false', '0', 'no' => false,
            default => defined('STDERR')
                && function_exists('stream_isatty')
                && @stream_isatty(STDERR),
        };
    }
}
