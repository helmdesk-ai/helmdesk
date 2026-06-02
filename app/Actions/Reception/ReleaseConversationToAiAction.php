<?php

namespace App\Actions\Reception;

use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Reception\ChannelAiAvailability;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 将客服接待中的会话释放回 AI 或访客等待状态。
 */
class ReleaseConversationToAiAction
{
    use AsAction;

    /**
     * 注入实时通知和渠道 AI 可用性服务。
     */
    public function __construct(
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly ChannelAiAvailability $aiAvailability,
        private readonly DispatchConversationAutoMessageAction $dispatchConversationAutoMessageAction,
    ) {}

    /**
     * 校验当前客服权限并释放会话给 AI 或待接待队列。
     */
    public function handle(Conversation $conversation, User $actor): Conversation
    {
        if ($conversation->status !== ConversationStatus::Open) {
            throw new BusinessException(__('conversation.errors.already_closed'));
        }

        if ($conversation->assigned_user_id === null) {
            throw new BusinessException(__('conversation.errors.already_ai_handling'));
        }

        if ((string) $conversation->assigned_user_id !== (string) $actor->id) {
            throw new BusinessException(__('conversation.errors.release_to_ai_not_allowed'));
        }

        $canUseAi = $this->conversationCanUseAi($conversation);

        $conversation = DB::transaction(function () use ($conversation, $actor, $canUseAi) {
            $nextInboxStatus = $canUseAi
                ? ConversationInboxStatus::AiHandling
                : ConversationInboxStatus::TeammatePending;
            $waitingForVisitorReply = $canUseAi && ! $this->lastMessageIsFromVisitor($conversation);
            $previousAssignedUserId = (string) $conversation->assigned_user_id;

            $conversation->update([
                'assigned_user_id' => null,
                'inbox_status' => $nextInboxStatus,
                'waiting_for_visitor_reply' => $waitingForVisitorReply,
            ]);

            ConversationEvent::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor->id,
                'type' => ConversationEventType::AssignmentChanged,
                'payload' => [
                    'source' => 'release_to_ai',
                    'previous_user_id' => $previousAssignedUserId,
                    'inbox_status' => $nextInboxStatus->value,
                    'waiting_for_visitor_reply' => $waitingForVisitorReply,
                ],
                'created_at' => now(),
            ]);

            return $conversation->fresh();
        });

        if ($conversation->inbox_status === ConversationInboxStatus::AiHandling) {
            $this->dispatchConversationAutoMessageAction->handle(
                $conversation,
                ConversationAutoMessageTrigger::AiWelcome,
            );
        }

        $this->realtimeNotifier->conversationChanged($conversation, 'conversation_released_to_ai', [
            'inbox_status' => $conversation->inbox_status->value,
        ]);

        return $conversation;
    }

    /**
     * 判断会话所属渠道是否仍可交给 AI 接待。
     */
    private function conversationCanUseAi(Conversation $conversation): bool
    {
        $conversation->loadMissing('channel');

        return $conversation->channel !== null
            && $this->aiAvailability->canUseAi($conversation->channel);
    }

    /**
     * 判断会话最后一条消息是否来自访客。
     */
    private function lastMessageIsFromVisitor(Conversation $conversation): bool
    {
        $lastMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $lastMessage?->role === MessageRole::Visitor;
    }
}
