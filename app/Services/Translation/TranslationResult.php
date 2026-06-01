<?php

namespace App\Services\Translation;

use Spatie\LaravelData\Data;

/**
 * 翻译调用的结果 DTO。
 *
 * 由 TranslatorContract 实现统一返回；字段是回填 conversation_messages.translations 所需的最小元数据集合：
 * 译文、检测到的源语言（provider 可能自动识别）、实际目标语言、driver 标识、模型名（仅 LLM driver 有）、
 * 调用延迟、计费字符数。调用方拿到后写入 payload，便于后续命中缓存、费用核算、问题排查。
 *
 */
class TranslationResult extends Data
{
    public function __construct(
        public string $text,
        public string $source_lang,
        public string $target_lang,
        public string $provider_slug,
        public ?string $model,
        public int $latency_ms,
        public ?int $char_count,
    ) {}
}
