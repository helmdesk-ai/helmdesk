<?php

namespace App\Actions\Inbox;

use App\Actions\Translation\ResolveConversationTranslationProviderAction;
use App\Data\Inbox\FormQueueInboxMessageTranslationsData;
use App\Data\WorkspaceUserContextData;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Jobs\Inbox\TranslateInboxConversationMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\LocalePreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 为收件箱当前可见消息排队补翻当前查看者语言。
 */
class QueueInboxConversationMessageTranslationsAction
{
    use AsAction;

    /**
     * 注入会话翻译供应商解析器。
     */
    public function __construct(
        private readonly ResolveConversationTranslationProviderAction $translationProviderResolver,
    ) {}

    /**
     * 校验会话和消息后派发逐条补翻任务。
     *
     * @param  list<string>  $messageIds
     */
    public function handle(Workspace $workspace, User $user, string $conversationId, array $messageIds): int
    {
        $conversation = Conversation::query()
            ->with(['channel', 'workspace'])
            ->where('workspace_id', $workspace->id)
            ->find($conversationId);

        if ($conversation === null || $conversation->channel === null) {
            throw new NotFoundHttpException;
        }

        if (! $this->translationProviderResolver->hasUsableProvider($conversation)) {
            return 0;
        }

        $messages = $this->messagesNeedingTranslation($conversation, $user, $messageIds);

        foreach ($messages as $message) {
            TranslateInboxConversationMessageJob::dispatch(
                (string) $message->id,
                $user->locale,
            )->afterCommit();
        }

        return $messages->count();
    }

    /**
     * 接收当前可见消息补翻请求并返回排队数量。
     */
    public function asController(Request $request, string $slug, string $conversationId): JsonResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $user = User::query()->findOrFail($ctx->user_id);
        $data = FormQueueInboxMessageTranslationsData::from($request);

        return response()->json([
            'queued_count' => $this->handle(
                workspace: $ctx->workspace(),
                user: $user,
                conversationId: $conversationId,
                messageIds: $data->message_ids,
            ),
        ]);
    }

    /**
     * 找出当前查看者语言缺失的文本消息。
     *
     * @param  list<string>  $messageIds
     * @return Collection<int, ConversationMessage>
     */
    private function messagesNeedingTranslation(Conversation $conversation, User $user, array $messageIds): Collection
    {
        $targetLocale = $user->locale;

        return ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('id', $messageIds)
            ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
            ->where('kind', MessageKind::Text)
            ->whereNotNull('content')
            ->whereNull('recalled_at')
            ->orderBy('seq_no')
            ->get()
            ->filter(function (ConversationMessage $message) use ($targetLocale, $user): bool {
                $payload = $message->payload ?? [];

                if (
                    $message->role === MessageRole::Teammate
                    && (string) $message->sender_user_id === (string) $user->id
                ) {
                    return false;
                }

                if ($message->content_locale !== null && LocalePreference::matches($message->content_locale, $targetLocale)) {
                    return false;
                }

                return ! isset($payload['translations'][$targetLocale]);
            })
            ->values();
    }
}
