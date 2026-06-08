<?php

namespace App\Actions\Translation;

use App\Data\Translation\MessageTranslationData;
use App\Enums\MessageRole;
use App\Enums\MessageTranslationOutcome;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Localization\LocalePreference;
use App\Services\Translation\Exceptions\TranslationException;
use App\Services\Translation\TranslationProviderPool;
use App\Services\Translation\TranslationResult;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为会话消息追加客服视角翻译。
 *
 * 翻译结果写入 payload.translations[targetLang]，content_locale 记录 content 的实际语言。
 * 具体用哪家翻译供应商由全局轮询池决定（见 TranslationProviderPool），本 Action 不再关心供应商选择。
 */
class TranslateConversationMessageAction
{
    use AsAction;

    /**
     * 注入全局翻译供应商轮询池。
     */
    public function __construct(
        private readonly TranslationProviderPool $pool,
    ) {}

    /**
     * 为消息追加翻译。
     */
    public function handle(ConversationMessage $message, Conversation $conversation, Channel $channel): bool
    {
        if (! filled($message->content)) {
            return false;
        }

        $targetLang = $this->resolveTargetLang($message, $conversation);
        if ($targetLang === null) {
            return false;
        }

        return $this->translateToTargetLang($message, $conversation, $targetLang)->isTranslated();
    }

    /**
     * 把指定消息翻译到给定目标语言，用于客服手动补翻已发送消息。
     */
    public function handleForTargetLang(ConversationMessage $message, Conversation $conversation, Channel $channel, string $targetLang): bool
    {
        return $this->handleForTargetLangWithOutcome($message, $conversation, $channel, $targetLang)->isTranslated();
    }

    /**
     * 把指定消息翻译到给定目标语言，并返回细分执行结果。
     */
    public function handleForTargetLangWithOutcome(ConversationMessage $message, Conversation $conversation, Channel $channel, string $targetLang): MessageTranslationOutcome
    {
        if (! in_array($message->role, [MessageRole::Visitor, MessageRole::Teammate, MessageRole::Ai], true)) {
            return MessageTranslationOutcome::Skipped;
        }

        if (! filled($message->content) || ! filled($targetLang)) {
            return MessageTranslationOutcome::Skipped;
        }

        return $this->translateToTargetLang($message, $conversation, $targetLang);
    }

    /**
     * 把一段文本翻译成指定语言，供发送前预览、自动回复预处理和摘要翻译共用。
     *
     * 供应商由全局轮询池决定（已启用且凭据完整者随机取用、失败轮询下一个）；池为空时抛降级异常。
     */
    public function translateContentForTargetLang(string $content, string $targetLang): TranslationResult
    {
        return $this->pool->translate($content, 'auto', $targetLang);
    }

    /**
     * 执行翻译并写入消息 payload。
     */
    private function translateToTargetLang(ConversationMessage $message, Conversation $conversation, string $targetLang): MessageTranslationOutcome
    {
        $payload = $message->payload ?? [];
        if (isset($payload['translations'][$targetLang])) {
            return MessageTranslationOutcome::Skipped;
        }

        if ($message->content_locale !== null && LocalePreference::matches($message->content_locale, $targetLang)) {
            return MessageTranslationOutcome::Skipped;
        }

        try {
            $result = $this->translateContentForTargetLang((string) $message->content, $targetLang);
        } catch (TranslationException $e) {
            Log::warning('消息翻译失败', [
                'message_id' => $message->id,
                'target_lang' => $targetLang,
                'error' => $e->getMessage(),
            ]);

            return MessageTranslationOutcome::Failed;
        }

        $updates = [];
        if ($message->content_locale === null) {
            $updates['content_locale'] = $result->source_lang;
        }

        if (LocalePreference::matches($result->source_lang, $targetLang)) {
            if ($updates !== []) {
                $message->update($updates);

                return MessageTranslationOutcome::Translated;
            }

            return MessageTranslationOutcome::Skipped;
        }

        $payload['translations'][$result->target_lang] = MessageTranslationData::fromTranslationResult($result)->toArray();
        $updates['payload'] = $payload;
        $message->update($updates);

        return MessageTranslationOutcome::Translated;
    }

    /**
     * 根据消息角色解析翻译目标语言。
     */
    private function resolveTargetLang(
        ConversationMessage $message,
        Conversation $conversation,
    ): ?string {
        if ($message->role === MessageRole::Visitor) {
            return $this->resolveAgentLocale($conversation);
        }

        return null;
    }

    /**
     * 解析客服端目标语言：取已分配接待客服的界面语言。
     */
    private function resolveAgentLocale(Conversation $conversation): ?string
    {
        if ($conversation->assigned_user_id === null) {
            return null;
        }

        $conversation->load('assignedUser');

        return $conversation->assignedUser?->locale;
    }
}
