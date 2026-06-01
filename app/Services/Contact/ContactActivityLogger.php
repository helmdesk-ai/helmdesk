<?php

namespace App\Services\Contact;

use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\User;

/**
 * 记录联系人相关操作日志。
 */
class ContactActivityLogger
{
    /**
     * 写入一条联系人活动记录。
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function record(
        Contact $contact,
        string $action,
        ?User $actor = null,
        ?Contact $relatedContact = null,
        ?array $payload = null,
    ): void {
        ContactActivityLog::query()->create([
            'workspace_id' => $contact->workspace_id,
            'contact_id' => $contact->id,
            'related_contact_id' => $relatedContact?->id,
            'action' => $action,
            'actor_user_id' => $actor?->id,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
