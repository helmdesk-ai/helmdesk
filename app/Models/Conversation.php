<?php

namespace App\Models;

use App\Casts\ConversationChannelContextCast;
use App\Enums\ConversationEntryMode;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\ConversationVisitorReplyStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use Closure;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $workspace_id
 * @property string|null $contact_id
 * @property string|null $assigned_user_id
 * @property string|null $channel_id
 * @property string|null $reception_plan_version_id
 * @property ConversationEntryMode|null $entry_mode
 * @property string $visitor_locale
 * @property ConversationSource $source
 * @property ConversationStatus $status
 * @property ConversationInboxStatus $inbox_status
 * @property bool $waiting_for_visitor_reply
 * @property string|null $subject
 * @property string|null $summary
 * @property string|null $summary_locale
 * @property array|null $summary_translations
 * @property int $summary_last_message_seq_no
 * @property Carbon|null $summary_generated_at
 * @property array|null $ai_context
 * @property string|null $last_message_preview
 * @property Carbon|null $last_message_at
 * @property int $unread_visitor_message_count
 * @property int $unread_agent_message_count
 * @property int $next_seq_no
 * @property Carbon|null $closed_at
 * @property mixed $use_factory
 * @property int|null $workspaces_count
 * @property int|null $channels_count
 * @property int|null $contacts_count
 * @property int|null $reception_plan_versions_count
 * @property int|null $assigned_users_count
 * @property int|null $messages_count
 * @property int|null $auto_message_receipts_count
 * @property int|null $events_count
 * @property-read Workspace $workspace
 * @property-read Channel|null $channel
 * @property-read Contact|null $contact
 * @property-read ReceptionPlanVersion|null $receptionPlanVersion
 * @property-read User|null $assignedUser
 * @property-read Collection|ConversationMessage[] $messages
 * @property-read ConversationMessage|null $latestMessage
 * @property-read Collection|ConversationAutoMessageReceipt[] $autoMessageReceipts
 * @property-read Collection|ConversationEvent[] $events
 * @property-read Collection|Tag[] $tags
 *
 * @method static \Database\Factories\ConversationFactory<self> factory($count = null, $state = [])
 */
class Conversation extends Model
{
    /**
     * 会话模型，保存一次客户接待的完整生命周期：来源渠道、接待方案版本快照、消息历史、状态流转与 AI 上下文。
     *
     * 业务约束：同 workspace + channel + contact 同一时刻最多一条 status=open 的会话；
     * closed 后允许同 contact 在同 channel 上再开新会话（由 conversations_one_open_per_contact_channel partial unique index 保证）。
     */
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_mode' => ConversationEntryMode::class,
            'source' => ConversationSource::class,
            'status' => ConversationStatus::class,
            'inbox_status' => ConversationInboxStatus::class,
            'waiting_for_visitor_reply' => 'boolean',
            'summary_translations' => 'array',
            'summary_last_message_seq_no' => 'integer',
            'summary_generated_at' => 'datetime',
            'ai_context' => 'array',
            'channel_context' => ConversationChannelContextCast::class,
            'last_message_at' => 'datetime',
            'unread_visitor_message_count' => 'integer',
            'unread_agent_message_count' => 'integer',
            'next_seq_no' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function waitingForVisitorReplyLabel(): ?string
    {
        return $this->waiting_for_visitor_reply
            ? ConversationVisitorReplyStatus::Waiting->label()
            : null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class)->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /**
     * 会话锁定的接待方案版本快照；即便渠道后续重新部署新版本，本会话仍按创建时版本回放。
     */
    public function receptionPlanVersion(): BelongsTo
    {
        return $this->belongsTo(ReceptionPlanVersion::class, 'reception_plan_version_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id')->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('seq_no');
    }

    /**
     * 会话最后一条消息，用于列表展示当前 viewer 视角下的最后消息摘要。
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany('seq_no');
    }

    /**
     * 会话已发送自动回复的幂等记录。
     */
    public function autoMessageReceipts(): HasMany
    {
        return $this->hasMany(ConversationAutoMessageReceipt::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * 本次会话的访客浏览轨迹，按访问时间排序。
     */
    public function pageViews(): HasMany
    {
        return $this->hasMany(ConversationPageView::class)->orderBy('viewed_at')->orderBy('id');
    }

    /**
     * 本次会话的有效标签（AI 自动或人工打）；不含已被人工抑制（removed_at）的记录。
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'conversation_tag_assignments')
            ->withPivot('source', 'confidence', 'reason', 'assigned_by_user_id', 'based_on_seq_no', 'created_at')
            ->wherePivotNull('removed_at');
    }

    /**
     * withCount / loadCount 计算展示用消息数时共用的过滤器：
     * 仅统计访客、AI、坐席的非空、未撤回文本消息。
     */
    public static function displayMessageCountQuery(): Closure
    {
        return function (Builder $query): void {
            $query
                ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
                ->where('kind', MessageKind::Text)
                ->whereNotNull('content')
                ->whereNull('recalled_at');
        };
    }
}
