<?php

namespace App\Data\Translation;

use App\Services\Translation\TranslationResult;
use Spatie\LaravelData\Data;

/**
 * 消息翻译结果数据。
 * 存储在 ConversationMessage.payload.translations[targetLang] 中，记录单条消息的翻译文本及元数据。
 */
class MessageTranslationData extends Data
{
    /**
     * 承载一条消息的翻译文本和 provider 追溯信息。
     */
    public function __construct(
        public string $text,
        public string $source_lang,
        public string $target_lang,
        public string $provider_slug,
        public int $latency_ms,
    ) {}

    /**
     * 从翻译服务返回的 TranslationResult 创建消息翻译数据。
     */
    public static function fromTranslationResult(TranslationResult $result): self
    {
        return new self(
            text: $result->text,
            source_lang: $result->source_lang,
            target_lang: $result->target_lang,
            provider_slug: $result->provider_slug,
            latency_ms: $result->latency_ms,
        );
    }
}
