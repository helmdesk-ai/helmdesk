<?php

namespace App\Models;

use App\Enums\ConversationAutoMessageTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $workspace_id
 * @property string $conversation_id
 * @property ConversationAutoMessageTrigger $trigger
 * @property string $idempotency_key
 * @property string|null $actor_user_id
 * @property string|null $conversation_event_id
 * @property string|null $message_id
 * @property int|null $conversations_count
 * @property int|null $messages_count
 * @property int|null $conversation_events_count
 * @property int|null $actor_users_count
 * @property-read Conversation $conversation
 * @property-read ConversationMessage|null $message
 * @property-read ConversationEvent|null $conversationEvent
 * @property-read User|null $actorUser
 */
class ConversationAutoMessageReceipt extends Model
{
    /**
     * 记录某个会话已发送过的自动回复幂等键。
     */
    use HasUlids;

    protected $guarded = [];

    /**
     * 返回字段类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => ConversationAutoMessageTrigger::class,
        ];
    }

    /**
     * 关联所属会话。
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * 关联自动回复；翻译失败且未发送时为空。
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'message_id');
    }

    /**
     * 关联触发此消息的会话事件。
     */
    public function conversationEvent(): BelongsTo
    {
        return $this->belongsTo(ConversationEvent::class, 'conversation_event_id');
    }

    /**
     * 关联触发此消息的客服。
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
