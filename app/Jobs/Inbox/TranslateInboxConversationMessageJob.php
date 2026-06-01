<?php

namespace App\Jobs\Inbox;

use App\Actions\Translation\TranslateConversationMessageAction;
use App\Models\ConversationMessage;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 收件箱当前查看者语言的单条消息补翻任务。
 */
class TranslateInboxConversationMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /**
     * 创建单条消息补翻任务。
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $targetLocale,
    ) {}

    /**
     * 用消息和目标语言去重同一条补翻任务。
     */
    public function uniqueId(): string
    {
        return $this->messageId.':'.$this->targetLocale;
    }

    /**
     * 翻译消息并通知收件箱刷新。
     */
    public function handle(
        TranslateConversationMessageAction $translateAction,
        ReceptionRealtimeNotifier $realtimeNotifier,
    ): void {
        $message = ConversationMessage::query()
            ->with('conversation.channel')
            ->findOrFail($this->messageId);
        $conversation = $message->conversation;
        $channel = $conversation->channel;

        $outcome = $translateAction->handleForTargetLangWithOutcome(
            message: $message,
            conversation: $conversation,
            channel: $channel,
            targetLang: $this->targetLocale,
        );

        if ($outcome->isFailed()) {
            $realtimeNotifier->conversationChanged(
                $conversation->refresh(),
                'message_translation_failed',
                meta: [
                    'message_id' => (string) $message->id,
                    'target_locale' => $this->targetLocale,
                ],
                channel: $channel,
            );

            return;
        }

        if (! $outcome->isTranslated()) {
            return;
        }

        $realtimeNotifier->conversationChanged(
            $conversation->refresh(),
            'message_translation_updated',
            meta: [
                'message_id' => (string) $message->id,
                'seq_no' => (int) $message->seq_no,
                'target_locale' => $this->targetLocale,
            ],
            channel: $channel,
        );
    }
}
