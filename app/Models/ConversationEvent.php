<?php

namespace App\Models;

use App\Actions\Conversation\RecordConversationTimelineEntryAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationTimelineEntryType;
use Database\Factories\ConversationEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property string $conversation_id
 * @property string|null $actor_user_id
 * @property ConversationEventType $type
 * @property array|null $payload
 * @property mixed $use_factory
 * @property int|null $conversations_count
 * @property int|null $actor_users_count
 * @property-read Conversation $conversation
 * @property-read User|null $actorUser
 *
 * @method static \Database\Factories\ConversationEventFactory<self> factory($count = null, $state = [])
 */
class ConversationEvent extends Model
{
    /**
     * 会话事件模型，记录转人工、关闭、重开等系统时间线事件。
     */

    /** @use HasFactory<ConversationEventFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * 记录事件对应的会话时间线索引。
     */
    protected static function booted(): void
    {
        static::created(function (ConversationEvent $event): void {
            RecordConversationTimelineEntryAction::run(
                entryType: ConversationTimelineEntryType::Event,
                entryId: (string) $event->id,
                conversationId: (string) $event->conversation_id,
                occurredAt: $event->created_at,
            );
        });
    }

    /**
     * 返回会话事件字段的类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationEventType::class,
            'payload' => 'array',
        ];
    }

    /**
     * 关联事件所属会话。
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * 关联触发事件的客服用户。
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
