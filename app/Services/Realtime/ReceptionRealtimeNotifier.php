<?php

namespace App\Services\Realtime;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Services\Reception\ReceptionMercureTopics;
use App\Services\Reception\ReceptionStateBuilder;

/**
 * 发送接待会话的实时变更通知。
 */
class ReceptionRealtimeNotifier
{
    /**
     * 注入实时消息发布器。
     */
    public function __construct(
        private readonly MercurePublisher $publisher,
    ) {}

    /**
     * 通知收件箱和访客端会话已变更。
     *
     * @param  array<string, mixed>  $meta
     */
    public function conversationChanged(Conversation $conversation, string $event, array $meta = [], ?Channel $channel = null): void
    {
        $conversation = Conversation::query()->findOrFail($conversation->id);
        $conversation->loadMissing(['channel', 'contact']);

        $basePayload = [
            'event' => $event,
            'conversation_id' => (string) $conversation->id,
            'contact_id' => $conversation->contact_id !== null ? (string) $conversation->contact_id : null,
            'occurred_at' => now()->toIso8601String(),
            'assigned_user_id' => $conversation->assigned_user_id !== null ? (string) $conversation->assigned_user_id : null,
            'status' => $conversation->status->value,
            'inbox_status' => $conversation->inbox_status->value,
            'last_message_preview' => $conversation->last_message_preview,
            'contact_name' => $conversation->contact?->name,
            'channel_name' => $conversation->channel?->name,
            ...$meta,
        ];

        $this->publisher->publish(
            ReceptionMercureTopics::inbox(),
            'reception',
            $basePayload,
        );

        $visitorPayload = [
            'event' => $event,
            'conversation_id' => (string) $conversation->id,
            'occurred_at' => $basePayload['occurred_at'],
        ];
        $state = $this->buildVisitorState($conversation, $channel);
        if ($state !== null) {
            $visitorPayload['state'] = $state;
        }

        $this->publisher->publish(
            ReceptionMercureTopics::conversation((string) $conversation->id),
            'reception',
            $visitorPayload,
        );
    }

    /**
     * 生成发给访客端的最新接待状态。
     *
     * @return array<string, mixed>|null
     */
    private function buildVisitorState(Conversation $conversation, ?Channel $channel = null): ?array
    {
        if ($conversation->channel_id === null || $conversation->contact_id === null) {
            return null;
        }

        if ($channel === null) {
            $channel = Channel::query()
                ->with(['receptionPlanVersion.plan'])
                ->find($conversation->channel_id);
        }

        if ($channel === null) {
            return null;
        }

        // 访客端接待状态仅对网站渠道有意义（浏览器订阅 Mercure）；
        // Telegram 等外部渠道没有 Web 访客画布，出站投递由各自渠道的发送 Job 负责。
        if ($channel->type !== ChannelType::Web) {
            return null;
        }

        $state = ReceptionStateBuilder::build($channel, $conversation, '')->toArray();
        unset($state['session_token']);

        return $state;
    }
}
