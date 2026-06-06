<?php

namespace App\Actions\Reception;

use App\Actions\Conversation\QueueConversationSummaryRefreshAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\User;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 关闭会话并写入状态变更事件。
 */
class CloseConversationAction
{
    use AsAction;

    public function __construct(
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
    ) {}

    public function handle(
        Conversation $conversation,
        ?User $actor = null,
    ): Conversation {
        if ($conversation->status === ConversationStatus::Closed) {
            throw new BusinessException(__('conversation.errors.already_closed'));
        }

        $conversation = DB::transaction(function () use ($conversation, $actor) {
            $conversation->update([
                'status' => ConversationStatus::Closed,
                'waiting_for_visitor_reply' => false,
                'closed_at' => now(),
            ]);

            ConversationEvent::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor?->id,
                'type' => ConversationEventType::StatusChanged,
                'payload' => [
                    'status' => ConversationStatus::Closed->value,
                ],
                'created_at' => now(),
            ]);

            return $conversation->fresh();
        });

        $this->realtimeNotifier->conversationChanged($conversation, 'conversation_closed', [
            'status' => ConversationStatus::Closed->value,
        ]);
        // 关单后只自动生成一次会话总结；标签跟随总结完成后再生成，避免消息追加阶段多次打标。
        QueueConversationSummaryRefreshAction::run($conversation, force: true);

        return $conversation;
    }
}
