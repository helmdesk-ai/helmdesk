<?php

namespace App\Actions\Native\Channel\Telegram;

use App\Actions\Reception\AppendTelegramVisitorMessageAction;
use App\Services\Telegram\TelegramWebhookAuthenticator;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Native bridge 入口：接收 Go 解析后的 Telegram 入站文本消息字段，校验 secret 后落库并回传会话状态。
 *
 * Telegram 的 webhook secret 仅存于服务端：Go 把请求头里的 secret 原样透传，由本 Action 与渠道存储的
 * webhook_secret 做恒定时间比较，不符则 403；校验通过后把跨语言小类型转换为业务参数调用消息追加，
 * 并返回 conversation_id 与 inbox_status 供 Go 决策是否唤起 AI actor。
 */
class ReceiveTelegramUpdateBridgeAction
{
    use AsAction;

    /**
     * 注入 webhook 鉴权与 Telegram 访客消息追加 Action。
     */
    public function __construct(
        private readonly TelegramWebhookAuthenticator $authenticator,
        private readonly AppendTelegramVisitorMessageAction $append,
    ) {}

    /**
     * 处理一条 Telegram 入站文本消息。
     *
     * @return array{conversation_id: string, inbox_status: string, visitor_message_id: ?string}
     */
    public function handle(
        string $code,
        string $secretToken,
        int $chatId,
        int $userId,
        ?string $firstName,
        ?string $lastName,
        ?string $username,
        string $text,
        int $messageId,
        ?string $languageCode = null,
        ?bool $isPremium = null,
        ?bool $isBot = null,
        ?string $chatType = null,
    ): array {
        $this->authenticator->authenticate($code, $secretToken);

        $result = $this->append->handle(
            $code,
            (string) $userId,
            $this->composeDisplayName($firstName, $lastName, $username),
            $text,
            $messageId,
            $chatId,
            $username,
            $languageCode,
            $isPremium,
            $isBot,
            $chatType,
        );

        $conversation = $result['conversation'];
        $message = $result['message'];

        return [
            'conversation_id' => (string) $conversation->id,
            'inbox_status' => $conversation->inbox_status->value,
            'visitor_message_id' => $message !== null ? (string) $message->id : null,
        ];
    }

    /**
     * 由 Telegram 用户字段拼出展示名：优先 first+last，其次 @username，否则留空。
     */
    private function composeDisplayName(?string $firstName, ?string $lastName, ?string $username): ?string
    {
        $name = trim(implode(' ', array_filter([$firstName, $lastName], static fn (?string $part): bool => filled($part))));
        if ($name !== '') {
            return $name;
        }

        if (filled($username)) {
            return '@'.$username;
        }

        return null;
    }
}
