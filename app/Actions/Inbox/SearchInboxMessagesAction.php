<?php

namespace App\Actions\Inbox;

use App\Data\Inbox\InboxMessageSearchResultData;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Search\ConversationMessageSearch;
use App\Services\Search\ConversationMessageVisibleTextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 在指定联系人的所有会话中搜索消息内容。
 */
class SearchInboxMessagesAction
{
    use AsAction;

    private const MAX_RESULTS = 50;

    /**
     * 注入消息内容搜索服务。
     */
    public function __construct(
        private readonly ConversationMessageSearch $messageSearch,
        private readonly ConversationMessageVisibleTextResolver $visibleTextResolver,
    ) {}

    /**
     * 通过 TNTSearch 搜索联系人关联会话中的消息，返回匹配结果列表。
     *
     * @return InboxMessageSearchResultData[]
     */
    public function handle(User $viewer, string $contactId, string $search): array
    {
        $conversationIds = Conversation::query()
            ->where('contact_id', $contactId)
            ->pluck('id')
            ->all();

        if ($conversationIds === []) {
            return [];
        }

        $messages = ConversationMessage::query()
            ->with(['senderUser', 'conversation.channel', 'conversation.contact'])
            ->whereIn('conversation_id', $conversationIds)
            ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $results = [];
        foreach ($messages as $message) {
            $visibleTexts = $this->visibleTextResolver->texts($message, $viewer);
            $matchedContent = $this->messageSearch->matchingText($search, $visibleTexts);

            if ($matchedContent === null) {
                continue;
            }

            $role = $message->role instanceof MessageRole
                ? $message->role
                : MessageRole::from((string) $message->role);
            $senderName = filled($message->sender_name)
                ? (string) $message->sender_name
                : $message->senderUser?->name;

            $results[] = new InboxMessageSearchResultData(
                id: (string) $message->id,
                conversation_id: (string) $message->conversation_id,
                role: $role->value,
                role_label: $role->label(),
                kind: $message->kind?->value,
                sender_name: $senderName,
                content: $message->content,
                matched_content: $matchedContent,
                occurred_at: $message->created_at?->toIso8601String() ?? '',
            );

            if (count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $results;
    }

    /**
     * 处理收件箱聊天记录搜索请求。
     */
    public function asController(Request $request, string $contactId): JsonResponse
    {
        $viewer = $request->user();

        $validated = $request->validate([
            'search' => ['required', 'string', 'min:1', 'max:200'],
        ]);

        $results = $this->handle($viewer, $contactId, $validated['search']);

        return response()->json(['results' => $results]);
    }
}
