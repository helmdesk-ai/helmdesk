<?php

namespace App\Actions\Reception;

use App\Data\Reception\ReceptionStateData;
use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Reception\ReceptionStateBuilder;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 访客撤回自己发送过的消息。
 *
 * 撤回不删除原内容（用于审计与离线渠道兜底），仅打 recalled_at 标记；
 * 前端基于该字段渲染撤回占位。已被撤回的消息不可重复撤回。
 */
class RecallVisitorMessageAction
{
    use AsAction;

    /**
     * 注入接待上下文与实时通知器。
     */
    public function __construct(
        private readonly ResolveReceptionContextAction $resolveReceptionContextAction,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
    ) {}

    /**
     * 撤回访客在当前会话内的指定消息并广播撤回事件。
     */
    public function handle(
        string $channelCode,
        ?string $sessionToken,
        string $messageId,
        ?string $userToken = null,
    ): ReceptionStateData {
        $context = $this->resolveReceptionContextAction->handle(
            $channelCode,
            $sessionToken,
            entryMode: null,
            visitorEnvironment: null,
            userToken: $userToken,
        );
        /** @var Conversation $conversation */
        $conversation = $context['conversation'];

        if ($conversation->status !== ConversationStatus::Open) {
            throw new BusinessException(__('conversation.errors.already_closed'));
        }

        $message = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($messageId)
            ->first();

        if ($message === null) {
            throw new NotFoundHttpException(__('conversation.errors.message_not_found'));
        }

        if ($message->role !== MessageRole::Visitor) {
            throw new BusinessException(__('conversation.errors.recall_not_owner'));
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
            channel: $context['channel'],
        );

        return ReceptionStateBuilder::build($context['channel'], $conversation, $context['session_token']);
    }
}
