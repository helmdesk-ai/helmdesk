<?php

namespace App\Jobs\Telegram;

use App\Actions\Reception\ResolveTelegramReceptionContextAction;
use App\Enums\ChannelType;
use App\Enums\IdentityType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageKind;
use App\Exceptions\TelegramApiException;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\ConversationMessage;
use App\Services\Telegram\TelegramBotApi;
use App\Services\Telegram\TelegramHtmlConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 把 AI / 客服的出站文本消息发送到 Telegram，并回写投递状态。
 *
 * 出站投递是 Telegram 渠道与网站渠道的核心差异：网站靠浏览器订阅 Mercure 拉取，
 * Telegram 必须主动调 Bot API 推送。发送成功标记 sent、失败标记 failed，供收件箱呈现投递结果。
 */
class SendTelegramMessageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30];

    /**
     * 创建 Telegram 出站发送任务。
     */
    public function __construct(public readonly string $messageId) {}

    /**
     * 解析渠道凭证与目标会话，调用 Bot API 发送并回写投递状态。
     */
    public function handle(TelegramBotApi $api, TelegramHtmlConverter $htmlConverter): void
    {
        $message = ConversationMessage::query()
            ->with(['conversation.channel', 'conversation.contact'])
            ->find($this->messageId);

        if ($message === null || $message->recalled_at !== null) {
            return;
        }

        // 文本消息需有正文；图片 / 文件媒体消息正文为空但有附件。
        $isMedia = in_array($message->kind, [MessageKind::Image, MessageKind::File], true);
        if (! $isMedia && ! filled($message->content)) {
            return;
        }

        // 幂等：已记录 Telegram message_id 说明此前已成功投递，重投（如对账扫描）直接跳过，避免重复发送。
        if (($message->payload['telegram']['message_id'] ?? null) !== null) {
            if ($message->delivery_status !== MessageDeliveryStatus::Sent) {
                $message->update(['delivery_status' => MessageDeliveryStatus::Sent]);
            }

            return;
        }

        $conversation = $message->conversation;
        $channel = $conversation?->channel;
        if ($channel === null || $channel->type !== ChannelType::Telegram || ! filled($channel->telegram_bot_token)) {
            return;
        }

        $chatId = $this->resolveChatId((string) $conversation->workspace_id, (string) $conversation->contact_id, $channel->code);
        if ($chatId === null) {
            Log::warning('Telegram 出站消息找不到目标 chat_id，标记投递失败。', [
                'message_id' => $this->messageId,
                'conversation_id' => (string) $conversation->id,
            ]);
            $message->update(['delivery_status' => MessageDeliveryStatus::Failed]);

            return;
        }

        try {
            $sent = $isMedia
                ? $this->sendMedia($api, $channel, $chatId, $message)
                : $api->sendMessage(
                    (string) $channel->telegram_bot_token,
                    $chatId,
                    // AI / 客服回复按 CommonMark 撰写，转成 Telegram HTML 子集后以 parse_mode=HTML 发送。
                    $htmlConverter->convert((string) $message->content),
                    $this->resolveReplyToMessageId($message),
                    'HTML',
                );
        } catch (TelegramApiException $e) {
            Log::warning('Telegram 出站消息发送失败。', [
                'message_id' => $this->messageId,
                'reason' => $e->getMessage(),
            ]);
            $message->update(['delivery_status' => MessageDeliveryStatus::Failed]);

            return;
        }

        $message->update([
            'delivery_status' => MessageDeliveryStatus::Sent,
            'payload' => $this->mergeSentMetadata($message, $sent, $chatId),
        ]);
    }

    /**
     * 读取出站媒体消息绑定的附件并以图片 / 文件形式发送到 Telegram。
     *
     * @return array<string, mixed>
     */
    private function sendMedia(TelegramBotApi $api, Channel $channel, int $chatId, ConversationMessage $message): array
    {
        $attachment = Attachment::query()
            ->where('attachable_type', $message->getMorphClass())
            ->where('attachable_id', $message->getKey())
            ->first();

        if ($attachment === null) {
            throw new TelegramApiException('Telegram 出站媒体消息缺少附件', 0);
        }

        $contents = $attachment->filesystem()->get($attachment->object_key);
        if ($contents === null) {
            throw new TelegramApiException('Telegram 出站媒体附件读取失败', 0);
        }

        $botToken = (string) $channel->telegram_bot_token;
        $caption = filled($message->content) ? (string) $message->content : null;
        $replyTo = $this->resolveReplyToMessageId($message);

        return $message->kind === MessageKind::Image
            ? $api->sendPhoto($botToken, $chatId, $contents, $attachment->original_name, $caption, $replyTo)
            : $api->sendDocument($botToken, $chatId, $contents, $attachment->original_name, $caption, $replyTo);
    }

    /**
     * 由联系人 Telegram 身份解析目标 chat_id（私聊场景 chat_id 即用户 id）。
     */
    private function resolveChatId(string $workspaceId, string $contactId, string $channelCode): ?int
    {
        $value = ContactIdentity::query()
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->where('type', IdentityType::ExternalId)
            ->where('namespace', ResolveTelegramReceptionContextAction::identityNamespace($channelCode))
            ->value('value');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 若出站消息引用了某条 Telegram 访客消息，解析其 Telegram message_id 用于回复线程。
     */
    private function resolveReplyToMessageId(ConversationMessage $message): ?int
    {
        if (! filled($message->quoted_message_id)) {
            return null;
        }

        $quoted = ConversationMessage::query()->find($message->quoted_message_id);
        $telegramMessageId = $quoted?->payload['telegram']['message_id'] ?? null;

        return is_int($telegramMessageId) ? $telegramMessageId : null;
    }

    /**
     * 合并出站 Telegram message_id 到消息 payload，保留已有翻译等字段。
     *
     * @param  array<string, mixed>  $sent
     * @return array<string, mixed>
     */
    private function mergeSentMetadata(ConversationMessage $message, array $sent, int $chatId): array
    {
        $payload = is_array($message->payload) ? $message->payload : [];
        $payload['telegram'] = [
            'message_id' => is_int($sent['message_id'] ?? null) ? $sent['message_id'] : null,
            'chat_id' => $chatId,
        ];

        return $payload;
    }

    /**
     * 记录出站发送任务最终失败原因。
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('SendTelegramMessageJob failed.', [
            'message_id' => $this->messageId,
            'reason' => $exception->getMessage(),
        ]);
    }
}
