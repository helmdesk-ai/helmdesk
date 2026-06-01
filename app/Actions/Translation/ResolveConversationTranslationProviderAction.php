<?php

namespace App\Actions\Translation;

use App\Data\Reception\ReceptionMessageTranslationConfigData;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\TranslationProvider;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 从会话锁定的接待方案版本中解析消息翻译供应商。
 */
class ResolveConversationTranslationProviderAction
{
    use AsAction;

    /**
     * 返回会话方案快照中配置且凭据可用的翻译供应商。
     */
    public function handle(Conversation $conversation, bool $requireCompleteCredentials = true): ?TranslationProvider
    {
        $providerId = ReceptionMessageTranslationConfigData::fromConversation($conversation)->provider_id;

        if ($providerId === null) {
            return null;
        }

        $provider = $conversation->workspace->translationProviders()->whereKey($providerId)->first();

        if ($provider === null) {
            return null;
        }

        if ($requireCompleteCredentials && ! $provider->hasCompleteCredentials()) {
            return null;
        }

        return $provider;
    }

    /**
     * 判断会话是否配置了可用于运行时翻译的供应商。
     */
    public function hasUsableProvider(Conversation $conversation): bool
    {
        return $this->handle($conversation) !== null;
    }

    /**
     * 按联系人最近一条锁定接待方案版本的会话解析翻译供应商。
     */
    public function forLatestContactConversation(Contact $contact, bool $requireCompleteCredentials = true): ?TranslationProvider
    {
        $conversation = $contact->conversations()
            ->whereNotNull('reception_plan_version_id')
            ->latest()
            ->first();

        if (! $conversation instanceof Conversation) {
            return null;
        }

        return $this->handle($conversation, $requireCompleteCredentials);
    }
}
