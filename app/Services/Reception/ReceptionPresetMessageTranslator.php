<?php

namespace App\Services\Reception;

use App\Actions\Translation\TranslateConversationMessageAction;
use App\Data\Reception\Plan\ReceptionMessageTranslationConfigData;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Localization\LocalePreference;
use App\Services\Translation\Exceptions\TranslationException;
use Illuminate\Support\Facades\Log;

/**
 * 翻译接待方案中需要直接发送给访客的预设文案。
 */
class ReceptionPresetMessageTranslator
{
    /**
     * 注入会话翻译能力。
     */
    public function __construct(
        private readonly TranslateConversationMessageAction $translateAction,
    ) {}

    /**
     * 按会话访客语言生成访客可见文案，并保留客服侧原文翻译负载。
     *
     * @return array{available: bool, content: string, content_locale: ?string, payload: array<string, array{text: string, source_lang: string, target_lang: string, provider_slug: string, latency_ms: int}>|null}
     */
    public function translateForVisitor(
        Conversation $conversation,
        ReceptionMessageTranslationConfigData $settings,
        string $content,
        ?User $actor = null,
        string $context = 'reception_preset_message',
    ): array {
        if (! $settings->enabled) {
            return ['available' => true, 'content' => $content, 'content_locale' => null, 'payload' => null];
        }

        $targetLang = $conversation->visitor_locale;

        try {
            $result = $this->translateAction->translateContentForTargetLang($conversation, $content, $targetLang);
        } catch (TranslationException $e) {
            Log::warning('接待方案预设文案翻译失败', [
                'context' => $context,
                'conversation_id' => (string) $conversation->id,
                'target_lang' => $targetLang,
                'error' => $e->getMessage(),
            ]);

            return ['available' => false, 'content' => $content, 'content_locale' => null, 'payload' => null];
        }

        if (LocalePreference::matches($result->source_lang, $targetLang)) {
            return ['available' => true, 'content' => $content, 'content_locale' => $result->source_lang, 'payload' => null];
        }

        return [
            'available' => true,
            'content' => $result->text,
            'content_locale' => $result->target_lang,
            'payload' => $this->sourceTranslationPayload(
                content: $content,
                sourceLang: $result->source_lang,
                contentLocale: $result->target_lang,
                actor: $actor,
            ),
        ];
    }

    /**
     * 把预设文案原文保存成客服侧可读翻译。
     *
     * 当操作者语言与原文语言匹配时使用操作者语言，否则回退到原文语言本身。
     * 不依赖 app()->getLocale() 以避免 Octane 跨请求串值。
     *
     * @return array<string, array{text: string, source_lang: string, target_lang: string, provider_slug: string, latency_ms: int}>
     */
    private function sourceTranslationPayload(string $content, string $sourceLang, string $contentLocale, ?User $actor): array
    {
        $actorLocale = $actor instanceof User ? (string) $actor->locale : null;
        $targetLang = match (true) {
            $actorLocale !== null && LocalePreference::matches($sourceLang, $actorLocale) => $actorLocale,
            default => $sourceLang,
        };

        return [
            $targetLang => [
                'text' => $content,
                'source_lang' => $contentLocale,
                'target_lang' => $targetLang,
                'provider_slug' => 'source',
                'latency_ms' => 0,
            ],
        ];
    }
}
