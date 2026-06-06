<?php

namespace App\Actions\Translation;

use App\Data\Translation\MessageTranslationData;
use App\Enums\MessageTranslationOutcome;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\Contact\ContactAiContext;
use App\Services\Localization\LocalePreference;
use App\Services\Translation\Exceptions\TranslationException;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为联系人 AI 摘要追加客服视角翻译。
 */
class TranslateContactAiSummaryAction
{
    use AsAction;

    /**
     * 注入通用文本翻译用例。
     */
    public function __construct(
        private readonly TranslateConversationMessageAction $translateAction,
    ) {}

    /**
     * 把联系人 AI 摘要翻译到指定目标语言并写入 contacts.ai_context。
     */
    public function handle(Contact $contact, string $targetLang): MessageTranslationOutcome
    {
        $context = $contact->ai_context;
        $summary = is_array($context) && is_array($context['summary'] ?? null)
            ? $context['summary']
            : null;

        if ($summary === null || ! filled($targetLang)) {
            return MessageTranslationOutcome::Skipped;
        }

        $sourceLocale = is_string($summary['source_locale'] ?? null) ? $summary['source_locale'] : null;
        if ($sourceLocale !== null && LocalePreference::matches($sourceLocale, $targetLang)) {
            return MessageTranslationOutcome::Skipped;
        }

        $translations = is_array($summary['translations'] ?? null) ? $summary['translations'] : [];
        if (isset($translations[$targetLang]) && is_array($translations[$targetLang])) {
            return MessageTranslationOutcome::Skipped;
        }

        // 联系人摘要无直接会话上下文：回溯最近一条锁定了接待方案版本的会话来解析翻译供应商。
        $conversation = $contact->conversations()
            ->whereNotNull('reception_plan_version_id')
            ->latest()
            ->first();

        if ($conversation === null) {
            return MessageTranslationOutcome::Skipped;
        }

        try {
            $translations[$targetLang] = [
                'profile_summary' => $this->translateString($conversation, $summary['profile_summary'] ?? null, $targetLang),
                'open_issues' => $this->translateList($conversation, $summary['open_issues'] ?? [], $targetLang),
                'preferences' => $this->translateList($conversation, $summary['preferences'] ?? [], $targetLang),
                'recent_topics' => $this->translateList($conversation, $summary['recent_topics'] ?? [], $targetLang),
            ];
        } catch (TranslationException $exception) {
            Log::warning('联系人 AI 摘要翻译失败', [
                'contact_id' => $contact->id,
                'target_lang' => $targetLang,
                'error' => $exception->getMessage(),
            ]);

            return MessageTranslationOutcome::Failed;
        }

        $summary['translations'] = $translations;
        $context['summary'] = $summary;
        $contact->update(['ai_context' => ContactAiContext::normalize($context)]);

        return MessageTranslationOutcome::Translated;
    }

    /**
     * 翻译单个非空字符串。
     */
    private function translateString(Conversation $conversation, mixed $value, string $targetLang): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $result = $this->translateAction->translateContentForConversation($conversation, trim($value), $targetLang);

        return MessageTranslationData::fromTranslationResult($result)->toArray();
    }

    /**
     * 翻译字符串列表，相同条目复用同一次翻译结果。
     *
     * @return list<array<string, mixed>>
     */
    private function translateList(Conversation $conversation, mixed $value, string $targetLang): array
    {
        if (! is_array($value)) {
            return [];
        }

        $cache = [];
        $translated = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }

            if (! array_key_exists($trimmed, $cache)) {
                $result = $this->translateAction->translateContentForConversation($conversation, $trimmed, $targetLang);
                $cache[$trimmed] = MessageTranslationData::fromTranslationResult($result)->toArray();
            }

            $translated[] = $cache[$trimmed];
        }

        return $translated;
    }
}
