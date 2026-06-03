<?php

namespace App\Actions\Inbox;

use App\Actions\Translation\ResolveConversationTranslationProviderAction;
use App\Data\Inbox\FormQueueInboxConversationSummaryTranslationsData;
use App\Jobs\Inbox\TranslateInboxConversationSummaryJob;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Localization\LocalePreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 为收件箱当前可见的会话摘要排队补翻当前查看者语言。
 */
class QueueInboxConversationSummaryTranslationsAction
{
    use AsAction;

    /**
     * 注入会话翻译供应商解析器。
     */
    public function __construct(
        private readonly ResolveConversationTranslationProviderAction $translationProviderResolver,
    ) {}

    /**
     * 校验当前会话归属后派发摘要补翻任务。
     *
     * @param  list<string>  $conversationIds
     */
    public function handle(User $user, string $conversationId, array $conversationIds): int
    {
        $anchor = Conversation::query()
            ->find($conversationId);

        if ($anchor === null || $anchor->contact_id === null) {
            throw new NotFoundHttpException;
        }

        if (! $this->translationProviderResolver->hasUsableProvider($anchor)) {
            return 0;
        }

        $conversations = $this->conversationsNeedingTranslation($user, (string) $anchor->contact_id, $conversationIds);

        foreach ($conversations as $conversation) {
            TranslateInboxConversationSummaryJob::dispatch(
                (string) $conversation->id,
                $user->locale,
            )->afterCommit();
        }

        return $conversations->count();
    }

    /**
     * 接收会话摘要补翻请求并返回排队数量。
     */
    public function asController(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();
        $data = FormQueueInboxConversationSummaryTranslationsData::from($request);

        return response()->json([
            'queued_count' => $this->handle(
                user: $user,
                conversationId: $conversationId,
                conversationIds: $data->conversation_ids,
            ),
        ]);
    }

    /**
     * 找出当前查看者语言缺失的会话摘要。
     *
     * @param  list<string>  $conversationIds
     * @return Collection<int, Conversation>
     */
    private function conversationsNeedingTranslation(User $user, string $contactId, array $conversationIds): Collection
    {
        return Conversation::query()
            ->where('contact_id', $contactId)
            ->whereIn('id', $conversationIds)
            ->whereNotNull('summary')
            ->get(['id', 'contact_id', 'reception_plan_version_id', 'summary', 'summary_locale', 'summary_translations'])
            ->filter(function (Conversation $conversation) use ($user): bool {
                if (! $this->translationProviderResolver->hasUsableProvider($conversation)) {
                    return false;
                }

                if ($conversation->summary_locale !== null && LocalePreference::matches($conversation->summary_locale, $user->locale)) {
                    return false;
                }

                $translations = $conversation->summary_translations ?? [];

                return ! isset($translations[$user->locale]['text']);
            })
            ->values();
    }
}
