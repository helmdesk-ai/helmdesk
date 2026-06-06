<?php

namespace App\Models;

use App\Actions\Channel\Telegram\DispatchTelegramOutboundMessageAction;
use App\Actions\Conversation\RecordConversationTimelineEntryAction;
use App\Enums\ConversationTimelineEntryType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use Database\Factories\ConversationMessageFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Searchable;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $conversation_id
 * @property string|null $sender_user_id
 * @property MessageRole $role
 * @property MessageKind $kind
 * @property string|null $content
 * @property string|null $content_locale
 * @property array|null $payload
 * @property float|null $confidence
 * @property string|null $client_msg_id
 * @property int $seq_no
 * @property MessageDeliveryStatus $delivery_status
 * @property string|null $quoted_message_id
 * @property Carbon|null $recalled_at
 * @property string $sender_name
 * @property mixed $use_factory
 * @property int|null $conversations_count
 * @property int|null $sender_users_count
 * @property int|null $attachments_count
 * @property int|null $quoted_messages_count
 * @property-read Conversation $conversation
 * @property-read User|null $senderUser
 * @property-read Collection|Attachment[] $attachments
 * @property-read ConversationMessage|null $quotedMessage
 *
 * @method static \Database\Factories\ConversationMessageFactory<self> factory($count = null, $state = [])
 */
class ConversationMessage extends Model
{
    /**
     * 会话消息模型，保存访客、AI、客服和工具调用产生的时间线消息。
     */

    /** @use HasFactory<ConversationMessageFactory> */
    use HasFactory, HasUlids, Searchable;

    protected $guarded = [];

    /**
     * 注册消息角色和内容类型组合校验，并在落库前自动分配会话内的单调 seq_no。
     */
    protected static function booted(): void
    {
        $validateRoleKind = static function (ConversationMessage $message): void {
            $role = $message->role instanceof MessageRole
                ? $message->role
                : MessageRole::from((string) $message->role);
            $kind = $message->kind instanceof MessageKind
                ? $message->kind
                : MessageKind::from((string) $message->kind);

            if (! $role->allowsKind($kind)) {
                throw ValidationException::withMessages([
                    'kind' => __('conversation.errors.invalid_role_kind_combination'),
                ]);
            }
        };

        static::creating(function (ConversationMessage $message) use ($validateRoleKind): void {
            $validateRoleKind($message);

            // seq_no 仅在未由调用方显式设定时自动分发；保留显式设定能力以便测试、Bridge 或
            // 未来跨渠道镜像消息使用。通过对 conversations.next_seq_no 原子自增取号，
            // 避免 SELECT max(seq_no)+1 风格的 race condition。
            if ($message->seq_no === null || $message->seq_no === 0) {
                $message->seq_no = self::allocateSeqNo($message->conversation_id);
            }
        });

        static::created(function (ConversationMessage $message): void {
            RecordConversationTimelineEntryAction::run(
                entryType: ConversationTimelineEntryType::Message,
                entryId: (string) $message->id,
                conversationId: (string) $message->conversation_id,
                occurredAt: $message->created_at,
            );

            // 出站消息（AI / 客服文本）若属于 Telegram 渠道会话，派发 Bot API 投递任务；
            // 其它角色与渠道在 Action 内被快速过滤，不影响网站渠道的 Mercure 推送路径。
            DispatchTelegramOutboundMessageAction::run($message);
        });

        static::updating($validateRoleKind);
    }

    /**
     * 为指定会话原子分配下一个 seq_no。
     */
    public static function allocateSeqNo(string $conversationId): int
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $row = DB::selectOne(
                'UPDATE conversations SET next_seq_no = next_seq_no + 1 WHERE id = ? RETURNING next_seq_no',
                [$conversationId],
            );

            if ($row === null) {
                throw new \RuntimeException("Conversation {$conversationId} not found while allocating seq_no.");
            }

            return (int) (is_array($row) ? $row['next_seq_no'] : $row->next_seq_no);
        }

        // MySQL / MariaDB 等不支持 RETURNING：在事务里用 SELECT ... FOR UPDATE 串行化。
        return DB::transaction(static function () use ($conversationId): int {
            $current = DB::selectOne(
                'SELECT next_seq_no FROM conversations WHERE id = ? FOR UPDATE',
                [$conversationId],
            );

            if ($current === null) {
                throw new \RuntimeException("Conversation {$conversationId} not found while allocating seq_no.");
            }

            $next = ((int) (is_array($current) ? $current['next_seq_no'] : $current->next_seq_no)) + 1;
            DB::update('UPDATE conversations SET next_seq_no = ? WHERE id = ?', [$next, $conversationId]);

            return $next;
        });
    }

    /**
     * 返回会话消息字段的类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'kind' => MessageKind::class,
            'payload' => 'array',
            'confidence' => 'float',
            'seq_no' => 'integer',
            'delivery_status' => MessageDeliveryStatus::class,
            'recalled_at' => 'datetime',
        ];
    }

    /**
     * 关联消息所属会话。
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * 关联发送消息的客服用户。
     */
    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id')->withTrashed();
    }

    /**
     * 关联消息绑定的附件。
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * 关联当前消息引用回复的目标消息。
     */
    public function quotedMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_message_id');
    }

    /**
     * 撤回时效窗口：消息创建后 N 分钟内允许撤回，超过即不可撤回。
     * 前端会基于此做按钮 disabled 提示，后端在 Action 里强校验。
     */
    public const RECALL_WINDOW_MINUTES = 2;

    /**
     * 判断该消息是否已被撤回。
     */
    public function isRecalled(): bool
    {
        return $this->recalled_at !== null;
    }

    /**
     * 打撤回标记并同步覆盖会话预览（若被撤回的是会话最近一条）。
     *
     * 不删除原 content：保留供审计与离线渠道兜底，前端基于 recalled_at 渲染占位。
     * 调用方仍需自行决定是否广播实时事件、是否刷新 fresh()。
     */
    public function markRecalled(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $this->update(['recalled_at' => now()]);

            // 全文搜索索引需要立即剔除撤回内容，避免 inbox 搜索到已撤回文本。
            $this->unsearchable();

            // 撤回的若是会话最近一条消息，预览也要同步覆盖，避免列表里残留原文本。
            if (
                $conversation->last_message_at !== null
                && $this->created_at !== null
                && $conversation->last_message_at->equalTo($this->created_at)
            ) {
                $conversation->update([
                    'last_message_preview' => __('conversation.message_recalled_placeholder'),
                ]);
            }
        });
    }

    /**
     * 判断该消息是否仍处于可撤回的时效窗口内。
     */
    public function isWithinRecallWindow(): bool
    {
        if ($this->created_at === null) {
            return false;
        }

        return $this->created_at->diffInSeconds(now(), absolute: true) <= self::RECALL_WINDOW_MINUTES * 60;
    }

    /**
     * 生成消息 payload 中保存的附件快照。
     *
     * @return array<string, mixed>
     */
    public static function attachmentSnapshot(Attachment $attachment): array
    {
        return [
            'id' => (string) $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'byte_size' => $attachment->byte_size,
            'width' => $attachment->metadata['width'] ?? null,
            'height' => $attachment->metadata['height'] ?? null,
        ];
    }

    /**
     * 生成无文本附件消息的会话预览文案。
     */
    public function attachmentPreview(): string
    {
        return $this->kind === MessageKind::Image ? '[图片]' : '[文件]';
    }

    /**
     * 返回全文检索索引所需的消息字段。
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'search_text' => $this->searchableText(),
        ];
    }

    /**
     * 判断消息是否包含可索引文本。撤回后的消息不再参与全文检索。
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->isRecalled() && $this->searchableText() !== '';
    }

    /**
     * 汇总访客正文和客服语言内容作为全文检索内容。
     * 翻译文本会一并索引，让客服可以用自己的界面语言检索跨语言消息。
     */
    private function searchableText(): string
    {
        $texts = [];
        if (is_string($this->content) && $this->content !== '') {
            $texts[] = $this->content;
        }

        $translations = $this->payload['translations'] ?? [];
        if (! is_array($translations)) {
            return implode("\n", $texts);
        }

        foreach ($translations as $translation) {
            $text = is_array($translation) ? ($translation['text'] ?? null) : null;
            if (is_string($text) && $text !== '') {
                $texts[] = $text;
            }
        }

        return implode("\n", $texts);
    }
}
