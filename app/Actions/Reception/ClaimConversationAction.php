<?php

namespace App\Actions\Reception;

use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\User;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 把待处理、AI 接待中或同事接待中的会话分配给当前客服。
 */
class ClaimConversationAction
{
    use AsAction;

    /**
     * 注入实时通知服务。
     */
    public function __construct(
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly DispatchConversationAutoMessageAction $dispatchConversationAutoMessageAction,
    ) {}

    /**
     * 将可接管的会话分配给当前客服。
     */
    public function handle(Conversation $conversation, User $actor): Conversation
    {
        if ($conversation->status !== ConversationStatus::Open) {
            throw new BusinessException(__('conversation.errors.already_closed'));
        }

        $autoMessageTrigger = null;
        $assignmentEventId = null;

        $claimed = DB::transaction(function () use ($conversation, $actor, &$autoMessageTrigger, &$assignmentEventId): ?Conversation {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedConversation instanceof Conversation) {
                return null;
            }

            if ($lockedConversation->status !== ConversationStatus::Open) {
                throw new BusinessException(__('conversation.errors.already_closed'));
            }

            if (! $this->canClaim($lockedConversation, $actor)) {
                return null;
            }

            $previousAssignedUserId = $lockedConversation->assigned_user_id !== null
                ? (string) $lockedConversation->assigned_user_id
                : null;
            $previousInboxStatus = $lockedConversation->inbox_status;
            $source = $this->assignmentSourceFor($lockedConversation, $actor);
            $autoMessageTrigger = $previousAssignedUserId === null
                && $previousInboxStatus !== ConversationInboxStatus::AiHandling
                    ? ConversationAutoMessageTrigger::TeammateJoined
                    : ConversationAutoMessageTrigger::TeammateTransferred;

            $lockedConversation->update([
                'inbox_status' => ConversationInboxStatus::TeammateHandling,
                'assigned_user_id' => $actor->id,
                'updated_at' => now(),
            ]);

            $assignmentEvent = ConversationEvent::query()->create([
                'conversation_id' => $lockedConversation->id,
                'actor_user_id' => $actor->id,
                'type' => ConversationEventType::AssignmentChanged,
                'payload' => [
                    'source' => $source,
                    'previous_user_id' => $previousAssignedUserId,
                    'previous_inbox_status' => $previousInboxStatus->value,
                    'user_id' => (string) $actor->id,
                ],
                'created_at' => now(),
            ]);
            $assignmentEventId = (string) $assignmentEvent->id;

            return $lockedConversation->fresh();
        });

        if (! $claimed instanceof Conversation) {
            throw new BusinessException(__('conversation.errors.claim_failed'));
        }

        $this->realtimeNotifier->conversationChanged($claimed, 'conversation_claimed', [
            'assigned_user_id' => (string) $actor->id,
        ]);

        if ($autoMessageTrigger instanceof ConversationAutoMessageTrigger) {
            $this->dispatchConversationAutoMessageAction->handle(
                $claimed,
                $autoMessageTrigger,
                $actor,
                idempotencyKey: $this->autoMessageIdempotencyKey($autoMessageTrigger, $assignmentEventId),
                conversationEventId: $assignmentEventId,
            );
        }

        return $claimed;
    }

    /**
     * 判断当前客服是否可以接起或接管这条会话。
     */
    private function canClaim(Conversation $conversation, User $actor): bool
    {
        if ($conversation->inbox_status === ConversationInboxStatus::TeammatePending) {
            return true;
        }

        if (
            $conversation->assigned_user_id === null
            && $conversation->inbox_status === ConversationInboxStatus::AiHandling
        ) {
            return true;
        }

        return $conversation->assigned_user_id !== null
            && (string) $conversation->assigned_user_id !== (string) $actor->id
            && $conversation->inbox_status === ConversationInboxStatus::TeammateHandling;
    }

    /**
     * 生成分配事件来源，方便时间线区分普通接单、AI 转人工和强制接管。
     */
    private function assignmentSourceFor(Conversation $conversation, User $actor): string
    {
        if (
            $conversation->assigned_user_id === null
            && $conversation->inbox_status === ConversationInboxStatus::AiHandling
        ) {
            return 'transfer_to_human';
        }

        if (
            $conversation->assigned_user_id !== null
            && (string) $conversation->assigned_user_id !== (string) $actor->id
        ) {
            return 'takeover';
        }

        return 'claim';
    }

    /**
     * 普通接入整段会话只发一次，接管/转接按分配事件发一次。
     */
    private function autoMessageIdempotencyKey(ConversationAutoMessageTrigger $trigger, ?string $assignmentEventId): string
    {
        if ($trigger !== ConversationAutoMessageTrigger::TeammateTransferred || ! filled($assignmentEventId)) {
            return $trigger->value;
        }

        return $trigger->value.':'.$assignmentEventId;
    }
}
