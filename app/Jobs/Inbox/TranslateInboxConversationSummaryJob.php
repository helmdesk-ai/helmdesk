<?php

namespace App\Jobs\Inbox;

use App\Actions\Translation\TranslateConversationSummaryAction;
use App\Models\Conversation;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 收件箱会话摘要的单条补翻任务。
 */
class TranslateInboxConversationSummaryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /**
     * 创建会话摘要补翻任务。
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $targetLocale,
    ) {}

    /**
     * 用会话和目标语言去重。
     */
    public function uniqueId(): string
    {
        return $this->conversationId.':'.$this->targetLocale;
    }

    /**
     * 翻译摘要并通知收件箱刷新。
     */
    public function handle(
        TranslateConversationSummaryAction $translateAction,
        ReceptionRealtimeNotifier $realtimeNotifier,
    ): void {
        $conversation = Conversation::query()
            ->with(['channel'])
            ->findOrFail($this->conversationId);

        $outcome = $translateAction->handle($conversation, $this->targetLocale);
        if (! $outcome->isTranslated()) {
            return;
        }

        $realtimeNotifier->conversationChanged(
            $conversation->refresh(),
            'conversation_summary_translation_updated',
            meta: ['target_locale' => $this->targetLocale],
            channel: $conversation->channel,
        );
    }
}
