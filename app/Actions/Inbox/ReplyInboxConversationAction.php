<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\AppendTeammateMessageAction;
use App\Data\Inbox\FormReplyInboxConversationData;
use App\Data\WorkspaceUserContextData;
use App\Enums\ConversationInboxStatus;
use App\Enums\InboxView;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\LocalePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 从收件箱发送客服回复。
 */
class ReplyInboxConversationAction
{
    use AsAction;

    /**
     * 注入客服消息追加 Action。
     */
    public function __construct(
        private readonly AppendTeammateMessageAction $appendTeammateMessageAction,
    ) {}

    /**
     * 向指定会话追加客服回复并返回刷新后的会话。
     */
    public function handle(Workspace $workspace, User $user, string $conversationId, FormReplyInboxConversationData $data): Conversation
    {
        $conversation = Conversation::query()
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $authorContent = (string) ($data->content ?? '');
        [$visitorContent, $visitorLocale, $sourceLocale] = $this->confirmedVisitorContent($conversation, $data);

        $this->appendTeammateMessageAction->handle(
            conversation: $conversation,
            actor: $user,
            content: $visitorContent ?? $authorContent,
            attachmentIds: $data->attachment_ids,
            clientMsgId: $data->client_msg_id,
            quotedMessageId: $data->quoted_message_id,
            contentLocale: $visitorLocale,
            authorContent: $visitorContent !== null ? $authorContent : null,
            authorLocale: $sourceLocale,
        );

        return $conversation->refresh();
    }

    /**
     * 接收收件箱回复表单并跳回对应会话。
     */
    public function asController(Request $request, string $conversationId): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $user = User::query()->findOrFail($ctx->user_id);

        $conversation = $this->handle(
            workspace: $ctx->workspace(),
            user: $user,
            conversationId: $conversationId,
            data: FormReplyInboxConversationData::from($request),
        );
        $view = $this->resolveViewFor($conversation, $user);

        return redirect()->route('workspace.inbox.show', [
            'view' => $view,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * 按会话当前归属推断回复后的目标视图。
     */
    private function resolveViewFor(Conversation $conversation, User $user): InboxView
    {
        if ((string) $conversation->assigned_user_id === (string) $user->id) {
            return InboxView::Mine;
        }

        if ($conversation->assigned_user_id !== null) {
            return InboxView::Teammates;
        }

        if ($conversation->inbox_status === ConversationInboxStatus::AiHandling) {
            return InboxView::Ai;
        }

        return InboxView::Pending;
    }

    /**
     * 只接收当前会话真实会发给访客的已确认内容。
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function confirmedVisitorContent(Conversation $conversation, FormReplyInboxConversationData $data): array
    {
        $text = $data->visitor_content !== null ? trim($data->visitor_content) : '';
        $visitorLocale = $data->visitor_locale !== null ? trim($data->visitor_locale) : '';
        $sourceLocale = $data->source_locale !== null ? trim($data->source_locale) : '';

        if ($text === '' || $visitorLocale === '' || $sourceLocale === '') {
            return [null, null, null];
        }

        $conversationVisitorLocale = $conversation->visitor_locale;

        if (! LocalePreference::matches($conversationVisitorLocale, $visitorLocale)) {
            throw new BusinessException(__('conversation.errors.reply_translation_stale'));
        }

        return [$text, $visitorLocale, $sourceLocale];
    }
}
