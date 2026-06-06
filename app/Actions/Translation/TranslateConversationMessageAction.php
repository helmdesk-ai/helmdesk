<?php

namespace App\Actions\Translation;

use App\Data\Translation\MessageTranslationData;
use App\Enums\MessageRole;
use App\Enums\MessageTranslationOutcome;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\TranslationProvider;
use App\Services\Localization\LocalePreference;
use App\Services\Translation\Exceptions\TranslationException;
use App\Services\Translation\TranslationResult;
use App\Services\Translation\TranslatorManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为会话消息追加客服视角翻译。
 *
 * 翻译结果写入 payload.translations[targetLang]，content_locale 记录 content 的实际语言。
 */
class TranslateConversationMessageAction
{
    use AsAction;

    /**
     * 注入翻译 driver 管理器。
     */
    public function __construct(
        private readonly TranslatorManager $translatorManager,
        private readonly ResolveConversationTranslationProviderAction $translationProviderResolver,
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
     * 把一段文本翻译成指定语言，供发送前预览和自动回复预处理共用。
     */
    public function translateContentForTargetLang(Conversation $conversation, string $content, string $targetLang): TranslationResult
    {
        return $this->translateContentForConversation($conversation, $content, $targetLang);
    }

    /**
     * 把一段文本翻译成指定语言，供绑定会话的翻译场景复用。
     *
     * 供应商来源于会话锁定的接待方案版本快照（translation_config.provider_id）；未配置则按降级路径抛异常。
     */
    public function translateContentForConversation(Conversation $conversation, string $content, string $targetLang): TranslationResult
    {
        $provider = $this->translationProviderResolver->handle($conversation);

        if ($provider === null) {
            throw new TranslationException(__('translation.driver_errors.no_default_provider'));
        }

        return $this->translateWithCache(
            provider: $provider,
            content: $content,
            targetLang: $targetLang,
        );
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
            $result = $this->translateContentForTargetLang($conversation, (string) $message->content, $targetLang);
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
     * 使用供应商、目标语言和正文作为缓存键，避免固定话术重复请求翻译服务。
     */
    private function translateWithCache(TranslationProvider $provider, string $content, string $targetLang): TranslationResult
    {
        $cacheKey = 'message_translation:'.sha1(json_encode([
            'provider' => (string) $provider->id,
            'provider_updated_at' => $provider->updated_at?->timestamp,
            'source_lang' => 'auto',
            'target_lang' => $targetLang,
            'content' => $content,
        ], JSON_THROW_ON_ERROR));

        $payload = Cache::remember($cacheKey, now()->addDays(30), function () use ($provider, $content, $targetLang): array {
            $result = $this->translatorManager
                ->driverFor($provider)
                ->translate($content, 'auto', $targetLang);

            return [
                'text' => $result->text,
                'source_lang' => $result->source_lang,
                'target_lang' => $result->target_lang,
                'provider_slug' => $result->provider_slug,
                'model' => $result->model,
                'latency_ms' => $result->latency_ms,
                'char_count' => $result->char_count,
            ];
        });

        return TranslationResult::from($payload);
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
