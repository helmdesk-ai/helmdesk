<?php

namespace App\Actions\Native\Channel\Telegram;

use App\Actions\Reception\AppendTelegramVisitorMediaAction;
use App\Services\Telegram\TelegramBotApi;
use App\Services\Telegram\TelegramWebhookAuthenticator;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Native bridge 入口：接收 Go 解析后的 Telegram 入站图片 / 文件更新，校验 secret 后下载文件并落库。
 *
 * 与文本入站分离：媒体需先经 getFile 解析下载路径、再拉取二进制（凭证 bot_token 仅 PHP 侧持有），
 * 故 Go 只传 file_id 等小类型，由本 Action 完成下载与入库。返回 caption 文本消息 id（若有）供 Go 唤起 AI。
 */
class ReceiveTelegramMediaBridgeAction
{
    use AsAction;

    /**
     * 注入 webhook 鉴权、Bot API 客户端与媒体消息追加 Action。
     */
    public function __construct(
        private readonly TelegramWebhookAuthenticator $authenticator,
        private readonly TelegramBotApi $api,
        private readonly AppendTelegramVisitorMediaAction $append,
    ) {}

    /**
     * 处理一条 Telegram 入站媒体消息。
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
        int $messageId,
        string $mediaKind,
        string $fileId,
        ?string $fileName,
        ?string $mimeType,
        ?string $caption,
    ): array {
        $channel = $this->authenticator->authenticate($code, $secretToken);

        $botToken = (string) $channel->telegram_bot_token;
        if ($botToken === '') {
            throw new NotFoundHttpException;
        }

        $file = $this->api->getFile($botToken, $fileId);
        $filePath = is_string($file['file_path'] ?? null) ? $file['file_path'] : '';
        if ($filePath === '') {
            throw new NotFoundHttpException;
        }

        $contents = $this->api->downloadFile($botToken, $filePath);

        $mediaKind = $mediaKind === 'image' ? 'image' : 'file';
        $resolvedName = filled($fileName) ? $fileName : basename($filePath);
        $resolvedMime = filled($mimeType)
            ? $mimeType
            : ($mediaKind === 'image' ? 'image/jpeg' : 'application/octet-stream');

        $result = $this->append->handle(
            $code,
            (string) $userId,
            $this->composeDisplayName($firstName, $lastName, $username),
            $mediaKind,
            $contents,
            $resolvedName,
            $resolvedMime,
            $caption,
            $messageId,
            $chatId,
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
