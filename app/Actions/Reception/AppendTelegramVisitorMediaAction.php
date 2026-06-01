<?php

namespace App\Actions\Reception;

use App\Enums\AttachmentPurpose;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentVisibility;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Storage\AttachmentPathGenerator;
use App\Services\Storage\AttachmentThumbnailer;
use App\Services\Storage\StorageProfileDisk;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 把 Telegram 入站图片 / 文件追加到接待会话：服务端落盘成 Attachment，并按 Web 渠道同构生成媒体消息。
 *
 * 与文本入站共用会话 / 联系人体系，差异在于：文件二进制已由 Bridge 经 getFile 下载传入，这里负责
 * 写入私有存储、建附件、建 Image/File 消息。Telegram message_id 作幂等键，规避 webhook 重投重复落库。
 * 若媒体带 caption，额外落一条文本访客消息并作为可唤起 AI 的返回值（媒体本身不唤起文本型 AI）。
 */
class AppendTelegramVisitorMediaAction
{
    use AsAction;

    private const PREVIEW_LENGTH = 120;

    /** Telegram 图片 / 文件 caption 上限 1024 字符。 */
    private const MAX_CAPTION_LENGTH = 1024;

    /**
     * 注入接待上下文解析、实时通知、存储配置解析、对象 key 生成与缩略图服务。
     */
    public function __construct(
        private readonly ResolveTelegramReceptionContextAction $resolveTelegramReceptionContextAction,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly StorageProfileResolver $profileResolver,
        private readonly AttachmentPathGenerator $pathGenerator,
        private readonly AttachmentThumbnailer $thumbnailer,
    ) {}

    /**
     * 解析上下文并追加一条 Telegram 媒体消息（可选附带 caption 文本消息）。
     *
     * @param  'image'|'file'  $mediaKind
     * @return array{conversation: Conversation, message: ?ConversationMessage}
     */
    public function handle(
        string $channelCode,
        string $telegramUserId,
        ?string $displayName,
        string $mediaKind,
        string $fileContents,
        string $fileName,
        string $mimeType,
        ?string $caption,
        int $telegramMessageId,
        int $telegramChatId,
    ): array {
        $context = $this->resolveTelegramReceptionContextAction->handle($channelCode, $telegramUserId, $displayName);
        /** @var Conversation $conversation */
        $conversation = $context['conversation'];
        $conversation->loadMissing('contact');
        $visitorSenderName = (string) ($conversation->contact?->name ?? $displayName ?? 'Telegram');

        // Telegram message_id 作幂等键：媒体消息已存在则整次更新视为已处理。
        $clientMsgId = 'tg_'.$telegramMessageId;
        if ($this->messageExistsForClientId($conversation->id, $clientMsgId)) {
            return ['conversation' => $conversation->fresh() ?? $conversation, 'message' => null];
        }

        $caption = $caption !== null ? Str::limit(trim($caption), self::MAX_CAPTION_LENGTH, '') : '';
        $kind = $mediaKind === 'image' ? MessageKind::Image : MessageKind::File;
        $purpose = $mediaKind === 'image' ? AttachmentPurpose::ConversationImage : AttachmentPurpose::ConversationFile;
        $telegramPayload = ['message_id' => $telegramMessageId, 'chat_id' => $telegramChatId];

        try {
            [$mediaMessage, $captionMessage, $attachment] = DB::transaction(function () use (
                $conversation, $visitorSenderName, $clientMsgId, $kind, $purpose,
                $fileContents, $fileName, $mimeType, $caption, $telegramPayload
            ): array {
                $mediaMessage = ConversationMessage::query()->create([
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Visitor,
                    'sender_name' => $visitorSenderName,
                    'kind' => $kind,
                    'content' => null,
                    'payload' => ['telegram' => $telegramPayload],
                    'client_msg_id' => $clientMsgId,
                ]);

                $attachment = $this->storeAttachment($conversation, $mediaMessage, $purpose, $fileContents, $fileName, $mimeType);

                $mediaMessage->update([
                    'payload' => [
                        'telegram' => $telegramPayload,
                        'attachments' => [ConversationMessage::attachmentSnapshot($attachment)],
                    ],
                ]);

                // 带 caption 时额外落一条文本访客消息，使其与普通文本一样可唤起 AI。
                $captionMessage = null;
                if ($caption !== '') {
                    $captionMessage = ConversationMessage::query()->create([
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'role' => MessageRole::Visitor,
                        'sender_name' => $visitorSenderName,
                        'kind' => MessageKind::Text,
                        'content' => $caption,
                        'payload' => ['telegram' => $telegramPayload],
                    ]);
                }

                $preview = $caption !== '' ? $caption : $mediaMessage->attachmentPreview();
                $conversation->update([
                    'last_message_at' => now(),
                    'last_message_preview' => Str::limit($preview, self::PREVIEW_LENGTH, ''),
                    'waiting_for_visitor_reply' => false,
                    'unread_agent_message_count' => 0,
                ]);
                Conversation::query()
                    ->whereKey($conversation->id)
                    ->increment('unread_visitor_message_count');

                return [$mediaMessage, $captionMessage, $attachment];
            });
        } catch (UniqueConstraintViolationException) {
            // 并发重投命中幂等唯一约束，按已处理返回当前状态。
            return ['conversation' => $conversation->fresh() ?? $conversation, 'message' => null];
        }

        $this->generateThumbnailBestEffort($attachment);

        $conversation = $conversation->fresh() ?? $conversation;

        $this->realtimeNotifier->conversationChanged(
            $conversation,
            'visitor_message_created',
            meta: [
                'message_id' => (string) $mediaMessage->id,
                'seq_no' => (int) $mediaMessage->seq_no,
                'client_msg_id' => $mediaMessage->client_msg_id,
            ],
            channel: $context['channel'],
        );

        return ['conversation' => $conversation, 'message' => $captionMessage];
    }

    /**
     * 把下载到的文件二进制写入私有存储并创建绑定到消息的附件记录。
     */
    private function storeAttachment(
        Conversation $conversation,
        ConversationMessage $message,
        AttachmentPurpose $purpose,
        string $contents,
        string $fileName,
        string $mimeType,
    ): Attachment {
        $profile = $this->profileResolver->resolveForNewUpload();
        $attachmentId = (string) Str::ulid();
        $objectKey = $this->pathGenerator->generate(
            attachmentId: $attachmentId,
            purpose: $purpose,
            workspaceId: (string) $conversation->workspace_id,
            originalName: $fileName,
            mimeType: $mimeType,
        );

        $disk = StorageProfileDisk::build($profile);
        if (! $disk->put($objectKey, $contents)) {
            throw new \RuntimeException('Telegram 媒体附件落盘失败');
        }

        $metadata = [];
        if (str_starts_with($mimeType, 'image/')) {
            // 宽高仅为可选元数据：损坏 / 截断图片时 getimagesizefromstring 返回 false 并发 Notice，
            // Laravel 会把该 Notice 升级为 ErrorException 中断落库，故用 @ 抑制（@ 压不住 OOM 等 fatal，无掩盖风险）。
            $size = @getimagesizefromstring($contents);
            if ($size !== false) {
                $metadata = ['width' => (int) $size[0], 'height' => (int) $size[1]];
            }
        }

        return Attachment::query()->create([
            'id' => $attachmentId,
            'workspace_id' => $conversation->workspace_id,
            'uploaded_by_user_id' => null,
            'storage_profile_id' => $profile->id,
            'disk' => $profile->driver,
            'bucket' => $profile->bucket,
            'object_key' => $objectKey,
            'original_name' => $fileName,
            'mime_type' => $mimeType,
            'extension' => $this->pathGenerator->extension($fileName, $mimeType),
            'byte_size' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'visibility' => AttachmentVisibility::Private,
            'purpose' => $purpose,
            'status' => AttachmentStatus::Attached,
            'attachable_type' => $message->getMorphClass(),
            'attachable_id' => $message->getKey(),
            'metadata' => $metadata,
            'uploaded_at' => now(),
            'attached_at' => now(),
        ]);
    }

    /**
     * 为图片附件生成预览缩略图；失败仅记录日志、不阻断入站落库。
     *
     * 缩略图是收件箱预览的可选增强（与 Web 上传同构，由 AttachmentUploadCompleter 沿用同一降级策略），
     * 非图片或生成失败时收件箱回退展示原图，因此这里按明确的业务降级处理。
     */
    private function generateThumbnailBestEffort(Attachment $attachment): void
    {
        try {
            $this->thumbnailer->generate($attachment);
        } catch (Throwable $e) {
            Log::warning('Telegram 媒体缩略图生成失败。', [
                'attachment_id' => (string) $attachment->id,
                'reason' => $e->getMessage(),
            ]);
        }
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
