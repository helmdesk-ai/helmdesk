<?php

namespace App\Data\Inbox;

use App\Data\Contact\ContactAiSummaryData;
use App\Data\Conversation\ContactConversationTagAggregateData;
use App\Data\CustomAttribute\ContactAttributeFieldData;
use App\Data\Tag\ContactTagData;
use App\Enums\IdentityType;
use App\Models\Contact;
use App\Models\ContactIdentity;
use Spatie\LaravelData\Data;

/**
 * 收件箱右侧联系人资料。
 * 显示在 pages/inbox/InboxContextPanel.vue，包含头像、身份和最近上下文。
 */
class InboxContactProfileData extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public string $type_label,
        public string $source,
        public string $source_label,
        public ?string $name,
        public string $avatar_url,
        public ?string $primary_email,
        public ?string $primary_email_identity_id,
        public ?string $primary_phone,
        public ?string $primary_phone_identity_id,
        public ?string $locale,
        public ?string $timezone,
        public ?string $country,
        public ?string $city,
        public ?string $note,
        public ?ContactAiSummaryData $ai_summary,
        public bool $is_important,
        public ?string $important_at,
        public ?string $last_seen_at,
        public ?string $created_at,
        /** @var ContactTagData[] */
        public array $tags,
        /** @var ContactAttributeFieldData[] */
        public array $custom_attributes = [],
        /** @var ContactConversationTagAggregateData[] 联系人「咨询概况」：其所有会话上的会话标签去重计数 */
        public array $conversation_tag_aggregates = [],
    ) {}

    /**
     * @param  ContactAttributeFieldData[]  $customAttributes
     * @param  ContactConversationTagAggregateData[]  $conversationTagAggregates
     */
    public static function fromModel(Contact $contact, array $customAttributes = [], array $conversationTagAggregates = []): self
    {
        $contact->loadMissing(['identities', 'tags']);
        $primaryEmailIdentity = self::primaryIdentity($contact, IdentityType::Email);
        $primaryPhoneIdentity = self::primaryIdentity($contact, IdentityType::Phone);

        return new self(
            id: $contact->id,
            type: $contact->type->value,
            type_label: $contact->type->label(),
            source: $contact->source->value,
            source_label: $contact->source->label(),
            name: $contact->name,
            avatar_url: $contact->avatar_url,
            primary_email: $contact->primary_email,
            primary_email_identity_id: $primaryEmailIdentity?->id,
            primary_phone: $contact->primary_phone,
            primary_phone_identity_id: $primaryPhoneIdentity?->id,
            locale: $contact->locale,
            timezone: $contact->timezone,
            country: $contact->country,
            city: $contact->city,
            note: $contact->note,
            ai_summary: ContactAiSummaryData::fromContext($contact->ai_context),
            is_important: $contact->is_important,
            important_at: $contact->important_at?->toIso8601String(),
            last_seen_at: $contact->last_seen_at?->toIso8601String(),
            created_at: $contact->created_at?->toIso8601String(),
            tags: $contact->tags
                ->whereNull('deleted_at')
                ->values()
                ->map(fn ($tag) => ContactTagData::fromModel($tag))
                ->all(),
            custom_attributes: $customAttributes,
            conversation_tag_aggregates: $conversationTagAggregates,
        );
    }

    private static function primaryIdentity(Contact $contact, IdentityType $type): ?ContactIdentity
    {
        return $contact->identities
            ->where('type', $type)
            ->sortBy('created_at')
            ->first();
    }
}
