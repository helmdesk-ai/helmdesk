<?php

namespace App\Actions\Reception;

use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Jobs\Conversation\GenerateConversationSubjectJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 把 Telegram 入站文本消息追加到接待会话，并刷新收件箱状态。
 *
 * 与网站访客消息共用会话/联系人/AI actor 体系，差异仅在传输：消息由 Telegram webhook 推入，
 * Telegram 的 message_id 用作幂等键，规避 webhook 重投导致的重复落库。
 */
class AppendTelegramVisitorMessageAction
{
    use AsAction;

    /** Telegram 单条文本上限即 4096 字符。 */
    public const MAX_CONTENT_LENGTH = 4096;

    private const PREVIEW_LENGTH = 120;

    /**
     * 注入 Telegram 接待上下文解析与实时通知服务。
     */
    public function __construct(
        private readonly ResolveTelegramReceptionContextAction $resolveTelegramReceptionContextAction,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly CaptureTelegramConversationContextAction $captureTelegramConversationContextAction,
    ) {}

    /**
     * 解析上下文并追加一条 Telegram 访客文本消息。
     *
     * $languageCode/$isPremium/$isBot/$chatType 来自 update payload 的 from/chat，落到会话渠道上下文；
     * 由 Go 在 2b 阶段透传，缺省为 null 时保留先前快照。
     *
     * @return array{conversation: Conversation, message: ?ConversationMessage}
     */
    public function handle(
        string $channelCode,
        string $telegramUserId,
        ?string $displayName,
        string $text,
        int $telegramMessageId,
        int $telegramChatId,
        ?string $username = null,
        ?string $languageCode = null,
        ?bool $isPremium = null,
        ?bool $isBot = null,
        ?string $chatType = null,
    ): array {
        $text = trim($text);

        // /start（含 deep-link payload）是 Telegram 打开 bot 的约定动作，不是真实提问：
        // 只确保会话存在以触发 AI 欢迎语，不把命令落库为访客消息、也不唤起 AI 回复。
        if ($this->isStartCommand($text)) {
            $context = $this->resolveTelegramReceptionContextAction->handle($channelCode, $telegramUserId, $displayName);

            return ['conversation' => $context['conversation'], 'message' => null];
        }

        if ($text === '') {
            throw ValidationException::withMessages(['content' => __('conversation.errors.empty_message')]);
        }
        $text = Str::limit($text, self::MAX_CONTENT_LENGTH, '');

        $context = $this->resolveTelegramReceptionContextAction->handle($channelCode, $telegramUserId, $displayName);
        /** @var Conversation $conversation */
        $conversation = $context['conversation'];
        $conversation->loadMissing('contact');

        $this->captureTelegramConversationContextAction->handle($conversation, [
            'tg_user_id' => $telegramUserId,
            'username' => $username,
            'language_code' => $languageCode,
            'is_premium' => $isPremium,
            'is_bot' => $isBot,
            'chat_type' => $chatType,
        ]);
        $visitorSenderName = (string) ($conversation->contact?->name ?? $displayName ?? 'Telegram');

        // Telegram message_id 作为幂等键，webhook 重投不会重复落库。
        $clientMsgId = 'tg_'.$telegramMessageId;
        if ($this->messageExistsForClientId($conversation->id, $clientMsgId)) {
            return ['conversation' => $conversation->fresh() ?? $conversation, 'message' => null];
        }

        try {
            $message = DB::transaction(function () use ($conversation, $text, $visitorSenderName, $clientMsgId, $telegramMessageId, $telegramChatId): ConversationMessage {
                $message = ConversationMessage::query()->create([
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Visitor,
                    'sender_name' => $visitorSenderName,
                    'kind' => MessageKind::Text,
                    'content' => $text,
                    'content_locale' => null,
                    'payload' => ['telegram' => ['message_id' => $telegramMessageId, 'chat_id' => $telegramChatId]],
                    'client_msg_id' => $clientMsgId,
                ]);

                $conversation->update([
                    'last_message_at' => now(),
                    'last_message_preview' => Str::limit($text, self::PREVIEW_LENGTH, ''),
                    'waiting_for_visitor_reply' => false,
                    'unread_agent_message_count' => 0,
                ]);

                Conversation::query()
                    ->whereKey($conversation->id)
                    ->increment('unread_visitor_message_count');

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // 并发重投命中幂等唯一约束，按已处理返回当前状态。
            return ['conversation' => $conversation->fresh() ?? $conversation, 'message' => null];
        }

        $conversation = $conversation->fresh() ?? $conversation;

        $this->realtimeNotifier->conversationChanged(
            $conversation,
            'visitor_message_created',
            meta: [
                'message_id' => (string) $message->id,
                'seq_no' => (int) $message->seq_no,
                'client_msg_id' => $message->client_msg_id,
            ],
            channel: $context['channel'],
        );

        $this->dispatchSubjectGenerationIfNeeded($conversation, $text);

        return ['conversation' => $conversation, 'message' => $message];
    }

    /**
     * 判断入站文本是否为 Telegram 的 /start 命令（含 deep-link payload 如 "/start ref_123"）。
     */
    private function isStartCommand(string $text): bool
    {
        return $text === '/start' || str_starts_with($text, '/start ');
    }

    /**
     * 有访客文本且会话主题为空时，异步补全会话主题。
     */
    private function dispatchSubjectGenerationIfNeeded(Conversation $conversation, string $content): void
    {
        if ($content === '' || filled($conversation->subject) || config('queue.default') === 'sync') {
            return;
        }

        GenerateConversationSubjectJob::dispatch((string) $conversation->id)
            ->afterCommit()
            ->delay(now()->addSeconds(10));
    }

    /**
     * 判断会话内是否已存在相同 client_msg_id 的消息。
     */
    private function messageExistsForClientId(string $conversationId, string $clientMsgId): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('client_msg_id', $clientMsgId)
            ->exists();
    }
}
