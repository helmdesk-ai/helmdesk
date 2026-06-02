<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property string $contact_id
 * @property string|null $related_contact_id
 * @property string|null $actor_user_id
 * @property string $action
 * @property array|null $payload
 * @property int|null $contacts_count
 * @property int|null $related_contacts_count
 * @property int|null $actors_count
 * @property-read Contact $contact
 * @property-read Contact|null $relatedContact
 * @property-read User|null $actor
 */
class ContactActivityLog extends Model
{
    /**
     * 联系人活动日志模型，记录创建、更新、合并、恢复和标签变更等操作历史。
     */
    use HasUlids;

    public const ACTION_DELETED = 'deleted';

    public const ACTION_RESTORED = 'restored';

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_IDENTITY_ADDED = 'identity_added';

    public const ACTION_IDENTITY_REPLACED = 'identity_replaced';

    public const ACTION_IDENTITY_DELETED = 'identity_deleted';

    public const ACTION_MERGED_INTO_CURRENT = 'merged_into_current';

    public const ACTION_MERGED_INTO_OTHER = 'merged_into_other';

    public const ACTION_TAG_ATTACHED = 'tag_attached';

    public const ACTION_TAG_DETACHED = 'tag_detached';

    public const ACTION_IMPORTANT_MARKED = 'important_marked';

    public const ACTION_IMPORTANT_UNMARKED = 'important_unmarked';

    protected $table = 'contact_activity_logs';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function relatedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'related_contact_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
