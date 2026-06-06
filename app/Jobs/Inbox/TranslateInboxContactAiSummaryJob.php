<?php

namespace App\Jobs\Inbox;

use App\Actions\Translation\TranslateContactAiSummaryAction;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 收件箱联系人 AI 摘要补翻任务。
 */
class TranslateInboxContactAiSummaryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /**
     * 创建联系人 AI 摘要补翻任务。
     */
    public function __construct(
        public readonly string $contactId,
        public readonly string $targetLocale,
    ) {}

    /**
     * 用联系人和目标语言去重。
     */
    public function uniqueId(): string
    {
        return $this->contactId.':'.$this->targetLocale;
    }

    /**
     * 翻译联系人 AI 摘要并通知收件箱刷新。
     */
    public function handle(
        TranslateContactAiSummaryAction $translateAction,
        ReceptionRealtimeNotifier $realtimeNotifier,
    ): void {
        $contact = Contact::query()
            ->findOrFail($this->contactId);

        $outcome = $translateAction->handle($contact, $this->targetLocale);
        if (! $outcome->isTranslated()) {
            return;
        }

        $conversation = Conversation::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('created_at')
            ->first();

        if ($conversation === null) {
            return;
        }

        $realtimeNotifier->conversationChanged(
            $conversation,
            'contact_ai_summary_translation_updated',
            meta: ['contact_id' => (string) $contact->id, 'target_locale' => $this->targetLocale],
        );
    }
}
