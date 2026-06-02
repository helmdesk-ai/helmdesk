<?php

namespace App\Actions\Reception;

use App\Data\Reception\AutoMessagesConfigData;
use App\Data\Reception\PersonaConfigData;
use App\Data\Reception\ReceptionMessageTranslationConfigData;
use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\ConversationEventType;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationAutoMessageReceipt;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Reception\AutoMessageTemplateRenderer;
use App\Services\Reception\ReceptionPresetMessageTranslator;
use App\Services\Reception\ReceptionStateBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 按接待方案快照向会话写入幂等自动回复。
 */
class DispatchConversationAutoMessageAction
{
    use AsAction;

    private const PREVIEW_LENGTH = 120;

    /**
     * 注入模板渲染与实时通知服务。
     */
    public function __construct(
        private readonly AutoMessageTemplateRenderer $renderer,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly ReceptionPresetMessageTranslator $messageTranslator,
    ) {}

    /**
     * 根据触发点写入自动回复；配置关闭或已发送时返回 null。
     */
    public function handle(
        Conversation $conversation,
        ConversationAutoMessageTrigger $trigger,
        ?User $actor = null,
        bool $notify = true,
        ?string $idempotencyKey = null,
        ?string $conversationEventId = null,
    ): ?ConversationMessage {
        [$autoMessagesConfig, $translationConfig, $templateVariables] = $this->resolveAutoMessageContext($conversation, $actor);
        $config = $autoMessagesConfig->forTrigger($trigger);
        if (! $config->enabled || ! filled($config->message)) {
            return null;
        }

        $receiptKey = $idempotencyKey ?? $trigger->value;
        $channel = $this->resolveChannel($conversation);
        $content = $this->renderer->render($config->message, $templateVariables);

        if (! filled($content)) {
            return null;
        }

        $translation = $this->messageTranslator->translateForVisitor($conversation, $translationConfig, $content, $actor, 'auto_message');
        $failureMode = $translationConfig->failure_mode;

        if (! $translation['available'] && $failureMode === AutoMessageTranslationFailureMode::Skip) {
            $recorded = $this->recordSkippedAutoMessage(
                conversation: $conversation,
                trigger: $trigger,
                actor: $actor,
                content: $content,
                receiptKey: $receiptKey,
                conversationEventId: $conversationEventId,
            );

            if ($recorded && $notify) {
                $this->realtimeNotifier->conversationChanged(
                    $conversation->refresh(),
                    'auto_message_translation_failed',
                    channel: $channel,
                );
            }

            return null;
        }

        try {
            $message = DB::transaction(function () use ($conversation, $channel, $trigger, $actor, $content, $receiptKey, $conversationEventId, $translation, $failureMode): ?ConversationMessage {
                $locked = Conversation::query()
                    ->whereKey($conversation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (ConversationAutoMessageReceipt::query()
                    ->where('conversation_id', $locked->id)
                    ->where('idempotency_key', $receiptKey)
                    ->exists()) {
                    return null;
                }

                [$role, $senderUserId, $senderName] = $this->messageIdentity($locked, $channel, $trigger, $actor);
                $payload = [
                    'source' => 'auto_message',
                    'trigger' => $trigger->value,
                    'rendered_from' => 'reception_plan_snapshot',
                    'reception_plan_version_id' => (string) $locked->reception_plan_version_id,
                    'idempotency_key' => $receiptKey,
                ];
                if ($translation['payload'] !== null) {
                    $payload['translations'] = $translation['payload'];
                }

                $message = ConversationMessage::query()->create([
                    'conversation_id' => $locked->id,
                    'sender_user_id' => $senderUserId,
                    'sender_name' => $senderName,
                    'role' => $role,
                    'kind' => MessageKind::Text,
                    'content' => $translation['content'],
                    'content_locale' => $translation['content_locale'],
                    'payload' => $payload,
                ]);

                if (! $translation['available']) {
                    $this->recordAutoMessageTranslationFailureEvent(
                        conversation: $locked,
                        trigger: $trigger,
                        mode: $failureMode,
                        content: $content,
                    );
                }

                ConversationAutoMessageReceipt::query()->create([
                    'conversation_id' => $locked->id,
                    'trigger' => $trigger->value,
                    'idempotency_key' => $receiptKey,
                    'actor_user_id' => $actor?->id,
                    'conversation_event_id' => $conversationEventId,
                    'message_id' => $message->id,
                ]);

                $locked->update([
                    'last_message_at' => now(),
                    'last_message_preview' => Str::limit($translation['content'], self::PREVIEW_LENGTH, ''),
                ]);
                Conversation::query()
                    ->whereKey($locked->id)
                    ->increment('unread_agent_message_count');

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            Log::debug('会话自动回复写入遇到并发唯一约束。', [
                'conversation_id' => (string) $conversation->id,
                'trigger' => $trigger->value,
                'idempotency_key' => $receiptKey,
            ]);
            $message = null;
        }

        if ($message instanceof ConversationMessage && $notify) {
            $this->realtimeNotifier->conversationChanged(
                $conversation->refresh(),
                $this->eventName($trigger),
                meta: [
                    'message_id' => (string) $message->id,
                    'seq_no' => (int) $message->seq_no,
                ],
                channel: $channel,
            );
        }

        return $message;
    }

    /**
     * 记录翻译失败且未发送的自动回复。
     */
    private function recordSkippedAutoMessage(
        Conversation $conversation,
        ConversationAutoMessageTrigger $trigger,
        ?User $actor,
        string $content,
        string $receiptKey,
        ?string $conversationEventId,
    ): bool {
        return DB::transaction(function () use ($conversation, $trigger, $actor, $content, $receiptKey, $conversationEventId): bool {
            $locked = Conversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (ConversationAutoMessageReceipt::query()
                ->where('conversation_id', $locked->id)
                ->where('idempotency_key', $receiptKey)
                ->exists()) {
                return false;
            }

            $this->recordAutoMessageTranslationFailureEvent(
                conversation: $locked,
                trigger: $trigger,
                mode: AutoMessageTranslationFailureMode::Skip,
                content: $content,
            );

            ConversationAutoMessageReceipt::query()->create([
                'conversation_id' => $locked->id,
                'trigger' => $trigger->value,
                'idempotency_key' => $receiptKey,
                'actor_user_id' => $actor?->id,
                'conversation_event_id' => $conversationEventId,
                'message_id' => null,
            ]);

            return true;
        });
    }

    /**
     * 写入自动回复翻译失败事件。
     */
    private function recordAutoMessageTranslationFailureEvent(
        Conversation $conversation,
        ConversationAutoMessageTrigger $trigger,
        AutoMessageTranslationFailureMode $mode,
        string $content,
    ): void {
        ConversationEvent::query()->create([
            'conversation_id' => $conversation->id,
            'actor_user_id' => null,
            'type' => ConversationEventType::AutoMessageTranslationFailed,
            'payload' => [
                'trigger' => $trigger->value,
                'mode' => $mode->value,
                'content' => $content,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * 从会话锁定版本读取自动回复配置和模板变量。
     *
     * @return array{0: AutoMessagesConfigData, 1: ReceptionMessageTranslationConfigData, 2: array{display_name: string, teammate_name: ?string}}
     */
    private function resolveAutoMessageContext(Conversation $conversation, ?User $actor): array
    {
        $snapshot = $conversation->receptionPlanVersion()->firstOrFail()->snapshot_config;
        $raw = $snapshot['auto_messages_config'] ?? null;
        if (! is_array($raw)) {
            throw new LogicException('Reception plan snapshot must contain auto_messages_config.');
        }
        $persona = PersonaConfigData::fromArray($snapshot['persona_config']);

        return [
            AutoMessagesConfigData::fromArray($raw),
            ReceptionMessageTranslationConfigData::fromSnapshot($snapshot),
            [
                'display_name' => $persona->display_name,
                'teammate_name' => $actor?->name,
            ],
        ];
    }

    /**
     * 解析自动回复发送身份，AI 消息复用普通 AI 回复的渠道展示身份。
     *
     * @return array{0: MessageRole, 1: ?string, 2: ?string}
     */
    private function messageIdentity(
        Conversation $conversation,
        Channel $channel,
        ConversationAutoMessageTrigger $trigger,
        ?User $actor,
    ): array {
        if ($trigger === ConversationAutoMessageTrigger::AiWelcome) {
            [$aiSenderName] = ReceptionStateBuilder::channelMessageIdentity($channel, $conversation);

            return [MessageRole::Ai, null, $aiSenderName];
        }

        if (! $actor instanceof User) {
            throw new BusinessException(__('conversation.errors.reply_not_allowed_for_assignee'));
        }

        return [MessageRole::Teammate, (string) $actor->id, $actor->name];
    }

    /**
     * 取会话渠道并补齐版本关联。
     */
    private function resolveChannel(Conversation $conversation): Channel
    {
        $channel = $conversation->channel()->firstOrFail();
        $channel->loadMissing('receptionPlanVersion.plan');

        return $channel;
    }

    /**
     * 返回实时事件名。
     */
    private function eventName(ConversationAutoMessageTrigger $trigger): string
    {
        return match ($trigger) {
            ConversationAutoMessageTrigger::AiWelcome => 'ai_message_created',
            ConversationAutoMessageTrigger::TeammateJoined,
            ConversationAutoMessageTrigger::TeammateTransferred => 'teammate_message_created',
        };
    }
}
