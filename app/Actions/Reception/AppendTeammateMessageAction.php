<?php

namespace App\Actions\Reception;

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Enums\AttachmentPurpose;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Conversation\ConversationReplyPermission;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Support\LocalePreference;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 向接待会话追加客服消息。
 */
class AppendTeammateMessageAction
{
    use AsAction;

    public const MAX_CONTENT_LENGTH = 8000;

    public const MAX_ATTACHMENT_COUNT = 10;

    private const PREVIEW_LENGTH = 120;

    /**
     * 注入实时通知和附件绑定服务。
     */
    public function __construct(
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly AttachUploadedAttachmentsAction $attachUploadedAttachmentsAction,
        private readonly ConversationReplyPermission $replyPermission,
    ) {}

    /**
     * 追加客服消息、绑定附件，并更新会话收件箱状态。
     *
     * 提供 $clientMsgId 时做幂等校验，弱网重发不会重复落库。
     */
    public function handle(
        Conversation $conversation,
        User $actor,
        string $content,
        array $attachmentIds = [],
        ?string $clientMsgId = null,
        ?string $quotedMessageId = null,
        ?string $contentLocale = null,
        ?string $authorContent = null,
        ?string $authorLocale = null,
    ): ConversationMessage {
        $denialMessageKey = $this->replyPermission->denialMessageKey($conversation, $actor);
        if ($denialMessageKey !== null) {
            throw new BusinessException(__($denialMessageKey));
        }

        $content = trim($content);
        if ($content === '' && $attachmentIds === []) {
            throw ValidationException::withMessages(['content' => __('conversation.errors.empty_message')]);
        }
        if (Str::length($content) > self::MAX_CONTENT_LENGTH) {
            throw ValidationException::withMessages(['content' => __('conversation.errors.message_too_long')]);
        }
        if (count($attachmentIds) > self::MAX_ATTACHMENT_COUNT) {
            throw ValidationException::withMessages(['attachment_ids' => __('validation.max.array', ['max' => self::MAX_ATTACHMENT_COUNT])]);
        }

        if ($clientMsgId !== null) {
            $existing = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_msg_id', $clientMsgId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $resolvedQuotedMessageId = $this->resolveQuotedMessageId($conversation->id, $quotedMessageId);
        $authorTranslationPayload = $this->authorTranslationPayload(
            actor: $actor,
            authorContent: $authorContent,
            authorLocale: $authorLocale,
            contentLocale: $contentLocale,
        );

        try {
            $message = DB::transaction(function () use ($conversation, $actor, $content, $contentLocale, $attachmentIds, $clientMsgId, $resolvedQuotedMessageId, $authorTranslationPayload) {
                $attachmentsById = collect();
                $resolvedAttachments = collect();

                if ($attachmentIds !== []) {
                    $attachmentsById = Attachment::query()
                        ->whereIn('id', $attachmentIds)
                        ->get()
                        ->keyBy(fn (Attachment $attachment): string => (string) $attachment->id);

                    $resolvedAttachments = collect($attachmentIds)
                        ->map(fn (string $attachmentId): ?Attachment => $attachmentsById->get($attachmentId))
                        ->filter();

                    if ($resolvedAttachments->count() !== count($attachmentIds)) {
                        throw ValidationException::withMessages(['attachment_ids' => __('attachments.errors.not_uploaded')]);
                    }
                }

                $messages = collect();
                $firstClientMsgIdConsumed = false;

                if ($content !== '') {
                    $messages->push(ConversationMessage::query()->create([
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'sender_user_id' => $actor->id,
                        'sender_name' => $actor->name,
                        'role' => MessageRole::Teammate,
                        'kind' => MessageKind::Text,
                        'content' => $content,
                        'content_locale' => $contentLocale,
                        'payload' => $authorTranslationPayload,
                        'client_msg_id' => $clientMsgId,
                        'quoted_message_id' => $resolvedQuotedMessageId,
                    ]));
                    $firstClientMsgIdConsumed = $clientMsgId !== null;
                }

                foreach ($resolvedAttachments as $attachment) {
                    $kind = $attachment->purpose === AttachmentPurpose::ConversationImage
                        ? MessageKind::Image
                        : MessageKind::File;

                    $attachmentMessage = ConversationMessage::query()->create([
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'sender_user_id' => $actor->id,
                        'sender_name' => $actor->name,
                        'role' => MessageRole::Teammate,
                        'kind' => $kind,
                        'content' => null,
                        'payload' => null,
                        'client_msg_id' => $firstClientMsgIdConsumed ? null : $clientMsgId,
                        'quoted_message_id' => $firstClientMsgIdConsumed ? null : $resolvedQuotedMessageId,
                    ]);
                    $firstClientMsgIdConsumed = $firstClientMsgIdConsumed || $clientMsgId !== null;

                    $attached = $this->attachUploadedAttachmentsAction->handle(
                        attachable: $attachmentMessage,
                        attachmentId: (string) $attachment->id,
                        workspaceId: (string) $conversation->workspace_id,
                        actor: $actor,
                        allowedPurposes: [AttachmentPurpose::ConversationImage, AttachmentPurpose::ConversationFile],
                    );

                    $attachmentMessage->update([
                        'payload' => [
                            'attachments' => [ConversationMessage::attachmentSnapshot($attached)],
                        ],
                    ]);

                    $messages->push($attachmentMessage);
                }

                /** @var ConversationMessage $lastMessage */
                $lastMessage = $messages->last();

                $update = [
                    'last_message_at' => now(),
                    'last_message_preview' => Str::limit($content !== '' ? $content : $lastMessage->attachmentPreview(), self::PREVIEW_LENGTH, ''),
                    'inbox_status' => ConversationInboxStatus::TeammateHandling,
                    'waiting_for_visitor_reply' => true,
                    'unread_visitor_message_count' => 0,
                ];

                $needsImplicitClaim = $conversation->assigned_user_id === null
                    || $conversation->inbox_status === ConversationInboxStatus::TeammatePending;

                if ($needsImplicitClaim) {
                    $affected = Conversation::query()
                        ->whereKey($conversation->id)
                        ->where(function ($query): void {
                            $query->whereNull('assigned_user_id')
                                ->orWhere('inbox_status', ConversationInboxStatus::TeammatePending);
                        })
                        ->update([
                            'assigned_user_id' => $actor->id,
                            'inbox_status' => ConversationInboxStatus::TeammateHandling,
                            'updated_at' => now(),
                        ]);

                    if ($affected > 0) {
                        ConversationEvent::query()->create([
                            'workspace_id' => $conversation->workspace_id,
                            'conversation_id' => $conversation->id,
                            'actor_user_id' => $actor->id,
                            'type' => ConversationEventType::AssignmentChanged,
                            'payload' => ['source' => 'reply', 'user_id' => (string) $actor->id],
                            'created_at' => now(),
                        ]);
                    }
                }

                $conversation->update($update);

                Conversation::query()
                    ->whereKey($conversation->id)
                    ->increment('unread_agent_message_count', $messages->count());

                return $messages->first() ?? throw ValidationException::withMessages(['content' => __('conversation.errors.empty_message')]);
            });
        } catch (UniqueConstraintViolationException) {
            if ($clientMsgId !== null) {
                $existing = ConversationMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('client_msg_id', $clientMsgId)
                    ->firstOrFail();

                return $existing;
            }

            throw new \RuntimeException('Unexpected unique constraint violation on conversation message insert.');
        }

        $conversation = $conversation->fresh() ?? $conversation;
        $message = $message->refresh();

        $this->realtimeNotifier->conversationChanged(
            $conversation,
            'teammate_message_created',
            meta: [
                'message_id' => (string) $message->id,
                'seq_no' => (int) $message->seq_no,
                'client_msg_id' => $message->client_msg_id,
            ],
        );

        return $message;
    }

    /**
     * 解析当前会话内未撤回的引用消息 ID。
     */
    private function resolveQuotedMessageId(string $conversationId, ?string $quotedMessageId): ?string
    {
        if ($quotedMessageId === null) {
            return null;
        }

        $exists = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereKey($quotedMessageId)
            ->whereNull('recalled_at')
            ->exists();

        return $exists ? $quotedMessageId : null;
    }

    /**
     * 把客服输入文本保存为当前客服语言视图。
     *
     * @return array{translations: array<string, array{text: string, source_lang: string, target_lang: string, provider_slug: string, latency_ms: int}>}|null
     */
    private function authorTranslationPayload(
        User $actor,
        ?string $authorContent,
        ?string $authorLocale,
        ?string $contentLocale,
    ): ?array {
        $text = $authorContent !== null ? trim($authorContent) : '';
        $sourceLocale = $authorLocale !== null ? trim($authorLocale) : '';
        $actorLocale = trim((string) $actor->locale);

        if ($text === '' || $sourceLocale === '' || $actorLocale === '') {
            return null;
        }

        if (! LocalePreference::matches($sourceLocale, $actorLocale)) {
            return null;
        }

        if ($contentLocale !== null && LocalePreference::matches($contentLocale, $actorLocale)) {
            return null;
        }

        return [
            'translations' => [
                $actorLocale => [
                    'text' => $text,
                    'source_lang' => $contentLocale ?? $sourceLocale,
                    'target_lang' => $actorLocale,
                    'provider_slug' => 'author',
                    'latency_ms' => 0,
                ],
            ],
        ];
    }
}
