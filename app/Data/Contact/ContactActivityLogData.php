<?php

namespace App\Data\Contact;

use App\Models\ContactActivityLog;
use Spatie\LaravelData\Data;

/**
 * 联系人活动日志数据。
 * 由后端组装后传给 resources/js/pages/contacts/Index.vue、Trash.vue 和 ContactDetailDrawer.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class ContactActivityLogData extends Data
{
    /**
     * @param  array<int, string>  $identity_values
     */
    public function __construct(
        public string $id,
        public string $action,
        public ?string $related_contact_id,
        public ?string $related_contact_name,
        public ?string $actor_name,
        public string $created_at,
        public array $identity_values,
        /** @var array<string, mixed>|null */
        public ?array $payload,
    ) {}

    public static function fromModel(ContactActivityLog $activityLog): self
    {
        $payload = $activityLog->payload ?? [];
        $identityValues = collect(data_get($payload, 'identity_values', []))
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->all();

        return new self(
            id: $activityLog->id,
            action: $activityLog->action,
            related_contact_id: $activityLog->relatedContact?->id,
            related_contact_name: $activityLog->relatedContact?->name
                ?? data_get($payload, 'related_contact_name'),
            actor_name: $activityLog->actor?->name,
            created_at: $activityLog->created_at?->toIso8601String() ?? '',
            identity_values: $identityValues,
            payload: $payload !== [] ? $payload : null,
        );
    }
}
