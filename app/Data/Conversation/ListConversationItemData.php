<?php

namespace App\Data\Conversation;

use App\Data\EnumOptionData;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * 会话列表项数据。
 */
class ListConversationItemData extends Data
{
    private const PREVIEW_LENGTH = 120;

    public function __construct(
        public string $id,
        public EnumOptionData $status,
        public EnumOptionData $inbox_status,
        public bool $waiting_for_visitor_reply,
        public ?string $waiting_for_visitor_reply_label,
        public EnumOptionData $source,
        public ?string $subject,
        public ?string $summary,
        public ?string $last_message_preview,
        public ?string $display_last_message_preview,
        public ?string $last_message_at,
        public ?string $closed_at,
        public string $created_at,
        public ?string $contact_id,
        public ?string $contact_name,
        public ?string $contact_primary_email,
        public ?string $contact_primary_phone,
        public bool $contact_is_important,
        public ?string $reception_plan_version_id,
        public ?string $reception_plan_name,
        public ?int $reception_plan_version_number,
        public ?string $assigned_user_id,
        public ?string $assigned_user_name,
        public int $unread_count = 0,
    ) {}

    public static function fromModel(Conversation $conversation, ?User $viewer = null): self
    {
        $planVersion = $conversation->receptionPlanVersion;
        $plan = $planVersion?->plan;

        return new self(
            id: $conversation->id,
            status: EnumOptionData::fromEnum($conversation->status),
            inbox_status: EnumOptionData::fromEnum($conversation->inbox_status),
            waiting_for_visitor_reply: (bool) $conversation->waiting_for_visitor_reply,
            waiting_for_visitor_reply_label: $conversation->waitingForVisitorReplyLabel(),
            source: EnumOptionData::fromEnum($conversation->source),
            subject: $conversation->subject,
            summary: $conversation->summary,
            last_message_preview: $conversation->last_message_preview,
            display_last_message_preview: self::displayLastMessagePreview($conversation, $viewer),
            last_message_at: $conversation->last_message_at?->toIso8601String(),
            closed_at: $conversation->closed_at?->toIso8601String(),
            created_at: $conversation->created_at?->toIso8601String() ?? '',
            contact_id: $conversation->contact_id,
            contact_name: $conversation->contact?->name,
            contact_primary_email: $conversation->contact?->primary_email,
            contact_primary_phone: $conversation->contact?->primary_phone,
            contact_is_important: (bool) $conversation->contact?->is_important,
            reception_plan_version_id: filled($conversation->reception_plan_version_id) ? (string) $conversation->reception_plan_version_id : null,
            reception_plan_name: filled($plan?->name) ? (string) $plan->name : null,
            reception_plan_version_number: $planVersion?->version_number !== null ? (int) $planVersion->version_number : null,
            assigned_user_id: $conversation->assigned_user_id,
            assigned_user_name: $conversation->assignedUser?->name,
            unread_count: (int) ($conversation->unread_count ?? 0),
        );
    }

    /**
     * 按当前 viewer 语言生成列表展示摘要。
     */
    private static function displayLastMessagePreview(Conversation $conversation, ?User $viewer): ?string
    {
        $message = $conversation->relationLoaded('latestMessage')
            ? $conversation->latestMessage
            : null;

        if (! $message instanceof ConversationMessage || $viewer === null || $message->isRecalled()) {
            return $conversation->last_message_preview;
        }

        $targetLang = in_array($message->role, [MessageRole::Visitor, MessageRole::Teammate, MessageRole::Ai], true)
            ? $viewer->locale
            : null;

        if (! filled($targetLang)) {
            return $conversation->last_message_preview;
        }

        $translated = $message->payload['translations'][(string) $targetLang]['text'] ?? null;
        if (! is_string($translated) || $translated === '') {
            return $conversation->last_message_preview;
        }

        return Str::limit($translated, self::PREVIEW_LENGTH, '');
    }
}
