<?php

namespace App\Actions\Reception;

use App\Data\Reception\HandoffDecisionData;
use App\Data\Reception\ReceptionMessageTranslationConfigData;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Jobs\Contact\GenerateContactAiSummaryJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Reception\ChannelTeammateAvailability;
use App\Services\Reception\ReceptionPresetMessageTranslator;
use App\Services\Reception\ReceptionStateBuilder;
use App\Support\LocalePreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 处理 AI 接待中的转人工请求。
 */
class RequestHandoffAction
{
    use AsAction;

    private const PREVIEW_LENGTH = 120;

    /**
     * 注入实时通知与人工可用性服务。
     */
    public function __construct(
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly ChannelTeammateAvailability $teammateAvailability,
        private readonly ReceptionPresetMessageTranslator $messageTranslator,
    ) {}

    /**
     * 判断当前是否允许转人工；仅人工可用时才将会话转入人工待接队列。
     *
     * 直接以 Conversation 入口保留既有会话归属，适配 actor 异步触发场景。
     */
    public function handle(
        Conversation $conversation,
        string $reason = 'ai_requested',
        ?string $quotedMessageId = null,
        ?string $summary = null,
    ): HandoffDecisionData {
        $channel = $this->resolveChannel($conversation);
        $status = $this->teammateAvailability->serviceStatus($channel, locale: $this->conversationLocale($conversation));
        $resolvedQuotedMessageId = $this->resolveQuotedVisitorMessageId((string) $conversation->id, $quotedMessageId);

        if (! $status->human_available) {
            $notice = $this->teammateAvailability->handoffNotice($channel, $status);
            $translation = $this->translateNotice($conversation, $notice, 'handoff_unavailable_notice');
            $this->appendUnavailableNotice(
                conversation: $conversation,
                channel: $channel,
                notice: $translation['content'],
                contentLocale: $translation['content_locale'],
                translationPayload: $translation['payload'],
                quotedMessageId: $resolvedQuotedMessageId,
            );

            return new HandoffDecisionData(
                accepted: false,
                reason: $status->unavailable_reason->value,
                notice: $translation['content'],
                human_available: false,
                business_hours_summary: $status->business_hours_summary,
                next_available_at: $status->next_available_at,
            );
        }

        $notice = $this->teammateAvailability->handoffNotice($channel, $status);
        $translation = $this->translateNotice($conversation, $notice, 'handoff_available_notice');
        $message = DB::transaction(function () use ($conversation, $channel, $translation, $reason, $resolvedQuotedMessageId): ConversationMessage {
            $message = $this->createNoticeMessage(
                conversation: $conversation,
                channel: $channel,
                notice: $translation['content'],
                contentLocale: $translation['content_locale'],
                translationPayload: $translation['payload'],
                quotedMessageId: $resolvedQuotedMessageId,
            );
            ConversationEvent::query()->create([
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'type' => ConversationEventType::HandoffRequested,
                'payload' => [
                    'reason' => $reason,
                    'actor_kind' => 'ai',
                    'reception_plan_version_id' => filled($conversation->reception_plan_version_id)
                        ? (string) $conversation->reception_plan_version_id
                        : null,
                ],
                'created_at' => now(),
            ]);

            $conversation->update([
                'assigned_user_id' => null,
                'inbox_status' => ConversationInboxStatus::TeammatePending,
                'last_message_at' => now(),
                'last_message_preview' => Str::limit($translation['content'], self::PREVIEW_LENGTH, ''),
                'waiting_for_visitor_reply' => false,
            ]);
            Conversation::query()
                ->whereKey($conversation->id)
                ->increment('unread_agent_message_count');

            return $message;
        });

        $conversation = $conversation->fresh();
        $this->persistAcceptedHandoffSummary($conversation, $summary);
        $this->realtimeNotifier->conversationChanged($conversation, 'handoff_requested', meta: $this->messageMeta($message), channel: $channel);

        return new HandoffDecisionData(
            accepted: true,
            reason: $reason,
            notice: $translation['content'],
            human_available: true,
            business_hours_summary: $status->business_hours_summary,
            next_available_at: $status->next_available_at,
        );
    }

    /**
     * 保存 AI 工具随转人工给出的接手摘要；没有摘要时异步强制生成。
     */
    private function persistAcceptedHandoffSummary(Conversation $conversation, ?string $summary): void
    {
        $summary = is_string($summary) ? trim($summary) : '';
        if ($summary === '') {

            return;
        }

        $latestSeqNo = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
            ->where('kind', MessageKind::Text)
            ->whereNotNull('content')
            ->whereNull('recalled_at')
            ->max('seq_no');

        $conversation->forceFill([
            'summary' => Str::limit(preg_replace('/\s+/u', ' ', $summary) ?: $summary, 1200, ''),
            'summary_locale' => $conversation->visitor_locale,
            'summary_translations' => null,
            'summary_last_message_seq_no' => $latestSeqNo !== null ? (int) $latestSeqNo : 0,
            'summary_generated_at' => now(),
        ])->save();

        $this->realtimeNotifier->conversationChanged($conversation, 'conversation_summary_updated');

        if ($conversation->contact_id !== null) {
            GenerateContactAiSummaryJob::dispatch((string) $conversation->contact_id)->afterCommit();
        }
    }

    /**
     * 写入转人工不可用提示，并用 AI 消息事件推送给访客端。
     */
    private function appendUnavailableNotice(
        Conversation $conversation,
        Channel $channel,
        string $notice,
        ?string $contentLocale,
        ?array $translationPayload,
        ?string $quotedMessageId,
    ): void {
        $message = DB::transaction(function () use ($conversation, $channel, $notice, $contentLocale, $translationPayload, $quotedMessageId): ConversationMessage {
            $message = $this->createNoticeMessage(
                conversation: $conversation,
                channel: $channel,
                notice: $notice,
                contentLocale: $contentLocale,
                translationPayload: $translationPayload,
                quotedMessageId: $quotedMessageId,
            );

            $conversation->update([
                'last_message_at' => now(),
                'last_message_preview' => Str::limit($notice, self::PREVIEW_LENGTH, ''),
                'waiting_for_visitor_reply' => true,
                'unread_visitor_message_count' => 0,
            ]);
            Conversation::query()
                ->whereKey($conversation->id)
                ->increment('unread_agent_message_count');

            return $message;
        });

        $conversation = $conversation->fresh();
        $this->realtimeNotifier->conversationChanged($conversation, 'ai_message_created', meta: $this->messageMeta($message), channel: $channel);
    }

    /**
     * 创建访客可见的转人工提示消息。
     */
    private function createNoticeMessage(
        Conversation $conversation,
        Channel $channel,
        string $notice,
        ?string $contentLocale,
        ?array $translationPayload,
        ?string $quotedMessageId,
    ): ConversationMessage {
        [$aiSenderName] = ReceptionStateBuilder::channelMessageIdentity($channel, $conversation);

        return ConversationMessage::query()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Ai,
            'kind' => MessageKind::Text,
            'content' => $notice,
            'content_locale' => $contentLocale,
            'payload' => $translationPayload !== null ? ['translations' => $translationPayload] : null,
            'sender_name' => $aiSenderName,
            'quoted_message_id' => $quotedMessageId,
        ]);
    }

    /**
     * 按接待方案翻译配置生成转人工提示的访客侧文案。
     *
     * @return array{available: bool, content: string, content_locale: ?string, payload: array<string, array{text: string, source_lang: string, target_lang: string, provider_slug: string, latency_ms: int}>|null}
     */
    private function translateNotice(Conversation $conversation, string $notice, string $context): array
    {
        return $this->messageTranslator->translateForVisitor(
            conversation: $conversation,
            settings: $this->translationConfig($conversation),
            content: $notice,
            context: $context,
        );
    }

    /**
     * 从会话锁定的版本快照读取访客侧预设文案翻译策略。
     */
    private function translationConfig(Conversation $conversation): ReceptionMessageTranslationConfigData
    {
        return ReceptionMessageTranslationConfigData::fromConversation($conversation);
    }

    /**
     * 生成实时事件里用于增量合并消息的元数据。
     *
     * @return array<string, int|string>
     */
    private function messageMeta(ConversationMessage $message): array
    {
        return [
            'message_id' => (string) $message->id,
            'seq_no' => (int) $message->seq_no,
        ];
    }

    /**
     * 返回当前会话内可引用的访客消息 ID。
     */
    private function resolveQuotedVisitorMessageId(string $conversationId, ?string $quotedMessageId): ?string
    {
        $quotedMessageId = $quotedMessageId !== null ? trim($quotedMessageId) : '';
        if ($quotedMessageId === '') {
            return null;
        }

        $exists = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', MessageRole::Visitor)
            ->whereKey($quotedMessageId)
            ->whereNull('recalled_at')
            ->exists();

        return $exists ? $quotedMessageId : null;
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
     * 根据访客语言偏好决定返回给运行时的人工服务摘要语言。
     */
    private function conversationLocale(Conversation $conversation): string
    {
        $conversation->loadMissing('channel');

        return LocalePreference::normalizeLaravel($conversation->visitor_locale);
    }
}
