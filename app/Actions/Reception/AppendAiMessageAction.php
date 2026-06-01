<?php

namespace App\Actions\Reception;

use App\Data\Reception\ReceptionStateData;
use App\Enums\ConversationInboxStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Reception\ChannelAiAvailability;
use App\Services\Reception\ReceptionStateBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 向接待会话追加 AI 回复消息并刷新会话状态。
 */
class AppendAiMessageAction
{
    use AsAction;

    public const MAX_CONTENT_LENGTH = 8000;

    private const PREVIEW_LENGTH = 120;

    /**
     * 注入实时通知和 AI 可用性服务。
     */
    public function __construct(
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly ChannelAiAvailability $aiAvailability,
    ) {}

    /**
     * 追加 AI 回复消息并标记为等待访客回复。
     *
     * 直接以会话为入口，沿用当前会话已绑定的渠道、联系人和接待方案。
     *
     * quotedMessageId 用于让 AI 回复显式引用某条访客消息（与人工客服回复一致的引用 UX）。
     * quotedMessageId 只接受当前会话内未撤回的消息。
     */
    public function handle(Conversation $conversation, string $content, ?string $quotedMessageId = null): ReceptionStateData
    {
        $content = trim($content);
        if ($content === '') {
            throw ValidationException::withMessages(['content' => __('conversation.errors.empty_message')]);
        }
        if (Str::length($content) > self::MAX_CONTENT_LENGTH) {
            throw ValidationException::withMessages(['content' => __('conversation.errors.message_too_long')]);
        }

        $channel = $this->resolveChannel($conversation);
        [$aiSenderName] = ReceptionStateBuilder::channelMessageIdentity($channel, $conversation);

        if ($conversation->inbox_status !== ConversationInboxStatus::AiHandling) {
            throw new BusinessException(__('conversation.errors.ai_reply_not_allowed'));
        }

        if (! $this->aiAvailability->canUseAi($channel)) {
            throw new BusinessException(__('conversation.errors.ai_reply_not_allowed'));
        }

        $resolvedQuotedMessageId = $this->resolveQuotedMessageId($conversation->id, $quotedMessageId);

        DB::transaction(function () use ($conversation, $content, $resolvedQuotedMessageId, $aiSenderName) {
            ConversationMessage::query()->create([
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'role' => MessageRole::Ai,
                'kind' => MessageKind::Text,
                'content' => $content,
                'content_locale' => $conversation->visitor_locale,
                'sender_name' => $aiSenderName,
                'quoted_message_id' => $resolvedQuotedMessageId,
            ]);

            $conversation->update([
                'last_message_at' => now(),
                'last_message_preview' => Str::limit($content, self::PREVIEW_LENGTH, ''),
                'waiting_for_visitor_reply' => true,
                'unread_visitor_message_count' => 0,
            ]);

            Conversation::query()
                ->whereKey($conversation->id)
                ->increment('unread_agent_message_count');
        });

        $conversation = $conversation->fresh();
        $latestMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('seq_no')
            ->first();

        $meta = $latestMessage !== null
            ? [
                'message_id' => (string) $latestMessage->id,
                'seq_no' => (int) $latestMessage->seq_no,
            ]
            : [];

        $this->realtimeNotifier->conversationChanged($conversation, 'ai_message_created', meta: $meta, channel: $channel);

        return ReceptionStateBuilder::build($channel, $conversation, '');
    }

    /**
     * 从 conversation 关联拿到 channel 并按需 eager-load receptionPlanVersion。
     */
    private function resolveChannel(Conversation $conversation): Channel
    {
        $channel = $conversation->channel;
        if ($channel === null) {
            throw new BusinessException(__('conversation.errors.ai_reply_not_allowed'));
        }
        if (! $channel->relationLoaded('receptionPlanVersion')) {
            $channel->load('receptionPlanVersion.plan');
        }

        return $channel;
    }

    /**
     * 解析当前会话内未撤回的引用消息 ID。
     */
    private function resolveQuotedMessageId(string $conversationId, ?string $quotedMessageId): ?string
    {
        if ($quotedMessageId === null || trim($quotedMessageId) === '') {
            return null;
        }

        $exists = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereKey($quotedMessageId)
            ->whereNull('recalled_at')
            ->exists();

        return $exists ? $quotedMessageId : null;
    }
}
