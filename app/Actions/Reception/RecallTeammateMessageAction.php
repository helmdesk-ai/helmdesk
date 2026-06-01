<?php

namespace App\Actions\Reception;

use App\Enums\ConversationStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 客服撤回会话内自己发送或 AI 发出的消息。
 *
 * 与访客撤回对称：仅打 recalled_at 标记，原 content 保留供审计；
 * 工具类消息（tool_call / tool_result）不允许撤回。
 */
class RecallTeammateMessageAction
{
    use AsAction;

    /**
     * 注入实时通知器。
     */
    public function __construct(
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
    ) {}

    /**
     * 撤回指定消息。当前用户必须是会话负责人，且消息属于自己或当前由 AI 发出。
     */
    public function handle(
        Conversation $conversation,
        User $actor,
        string $messageId,
    ): ConversationMessage {
        if ($conversation->status !== ConversationStatus::Open) {
            throw new BusinessException(__('conversation.errors.already_closed'));
        }

        if (
            $conversation->assigned_user_id !== null
            && (string) $conversation->assigned_user_id !== (string) $actor->id
        ) {
            throw new BusinessException(__('conversation.errors.reply_not_allowed_for_assignee'));
        }

        $message = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('workspace_id', $conversation->workspace_id)
            ->whereKey($messageId)
            ->first();

        if ($message === null) {
            throw new NotFoundHttpException(__('conversation.errors.message_not_found'));
        }

        // 客服可以撤回：自己发出的消息、或 AI 在自己接管下的兜底回复；不允许撤回访客或工具消息。
        $isOwnTeammateMessage = $message->role === MessageRole::Teammate
            && (string) $message->sender_user_id === (string) $actor->id;
        $isAiMessage = $message->role === MessageRole::Ai;

        if (! $isOwnTeammateMessage && ! $isAiMessage) {
            throw new BusinessException(__('conversation.errors.recall_not_owner'));
        }

        if (in_array($message->kind, [MessageKind::ToolCall, MessageKind::ToolResult], true)) {
            throw new BusinessException(__('conversation.errors.recall_kind_not_allowed'));
        }

        if ($message->isRecalled()) {
            throw new BusinessException(__('conversation.errors.recall_already_recalled'));
        }

        $message->markRecalled($conversation);

        $conversation = $conversation->fresh() ?? $conversation;
        $this->realtimeNotifier->conversationChanged(
            $conversation,
            'message_recalled',
            meta: ['message_id' => (string) $message->id],
        );

        return $message->refresh();
    }
}
