<?php

namespace App\Actions\Inbox;

use App\Actions\Translation\ResolveConversationTranslationProviderAction;
use App\Data\Inbox\FormQueueInboxContactAiSummaryTranslationData;
use App\Jobs\Inbox\TranslateInboxContactAiSummaryJob;
use App\Models\Contact;
use App\Services\Localization\LocalePreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 为收件箱右侧联系人 AI 摘要排队补翻当前查看者语言。
 */
class QueueInboxContactAiSummaryTranslationAction
{
    use AsAction;

    /**
     * 注入会话翻译供应商解析器。
     */
    public function __construct(
        private readonly ResolveConversationTranslationProviderAction $translationProviderResolver,
    ) {}

    /**
     * 校验联系人归属和摘要状态后派发补翻任务。
     */
    public function handle(string $contactId, string $targetLocale): int
    {
        $contact = Contact::query()
            ->find($contactId);

        if ($contact === null) {
            throw new NotFoundHttpException;
        }

        if ($this->translationProviderResolver->forLatestContactConversation($contact) === null) {
            return 0;
        }

        $summary = is_array($contact->ai_context) && is_array($contact->ai_context['summary'] ?? null)
            ? $contact->ai_context['summary']
            : null;

        if ($summary === null) {
            return 0;
        }

        $sourceLocale = is_string($summary['source_locale'] ?? null) ? $summary['source_locale'] : null;
        if ($sourceLocale !== null && LocalePreference::matches($sourceLocale, $targetLocale)) {
            return 0;
        }

        $translations = is_array($summary['translations'] ?? null) ? $summary['translations'] : [];
        if (isset($translations[$targetLocale]) && is_array($translations[$targetLocale])) {
            return 0;
        }

        TranslateInboxContactAiSummaryJob::dispatch((string) $contact->id, $targetLocale)->afterCommit();

        return 1;
    }

    /**
     * 接收联系人 AI 摘要补翻请求并返回排队数量。
     */
    public function asController(Request $request, string $contactId): JsonResponse
    {
        $data = FormQueueInboxContactAiSummaryTranslationData::from($request);

        return response()->json([
            'queued_count' => $this->handle(
                contactId: $contactId,
                targetLocale: $data->target_locale,
            ),
        ]);
    }
}
