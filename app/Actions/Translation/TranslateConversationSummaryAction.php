<?php

namespace App\Actions\Translation;

use App\Data\Translation\MessageTranslationData;
use App\Enums\MessageTranslationOutcome;
use App\Models\Conversation;
use App\Services\Localization\LocalePreference;
use App\Services\Translation\Exceptions\TranslationException;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为会话摘要追加客服视角翻译。
 */
class TranslateConversationSummaryAction
{
    use AsAction;

    /**
     * 注入通用文本翻译用例。
     */
    public function __construct(
        private readonly TranslateConversationMessageAction $translateAction,
    ) {}

    /**
     * 把会话摘要翻译到指定目标语言并写入 summary_translations。
     */
    public function handle(Conversation $conversation, string $targetLang): MessageTranslationOutcome
    {
        if (! filled($conversation->summary) || ! filled($targetLang)) {
            return MessageTranslationOutcome::Skipped;
        }

        if ($conversation->summary_locale !== null && LocalePreference::matches($conversation->summary_locale, $targetLang)) {
            return MessageTranslationOutcome::Skipped;
        }

        $translations = $conversation->summary_translations ?? [];
        if (isset($translations[$targetLang]['text']) && is_string($translations[$targetLang]['text'])) {
            return MessageTranslationOutcome::Skipped;
        }

        try {
            $result = $this->translateAction->translateContentForTargetLang(
                content: (string) $conversation->summary,
                targetLang: $targetLang,
            );
        } catch (TranslationException $exception) {
            Log::warning('会话摘要翻译失败', [
                'conversation_id' => $conversation->id,
                'target_lang' => $targetLang,
                'error' => $exception->getMessage(),
            ]);

            return MessageTranslationOutcome::Failed;
        }

        if (LocalePreference::matches($result->source_lang, $targetLang)) {
            if ($conversation->summary_locale === null) {
                $conversation->update(['summary_locale' => $result->source_lang]);
            }

            return MessageTranslationOutcome::Skipped;
        }

        $translations[$targetLang] = MessageTranslationData::fromTranslationResult($result)->toArray();
        $conversation->update([
            'summary_locale' => $conversation->summary_locale ?? $result->source_lang,
            'summary_translations' => $translations,
        ]);

        return MessageTranslationOutcome::Translated;
    }
}
