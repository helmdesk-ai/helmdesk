<?php

namespace App\Actions\Reception;

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Data\Reception\ReceptionStateData;
use App\Enums\AttachmentPurpose;
use App\Enums\ConversationEntryMode;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Jobs\Conversation\GenerateConversationSubjectJob;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Reception\ReceptionStateBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 向接待会话追加访客消息，并更新收件箱状态。
 */
class AppendVisitorMessageAction
{
    use AsAction;

    public const MAX_CONTENT_LENGTH = 4000;

    public const MAX_ATTACHMENT_COUNT = 10;

    private const PREVIEW_LENGTH = 120;

    /**
     * 注入接待上下文、实时通知和附件绑定服务。
     */
    public function __construct(
        private readonly ResolveReceptionContextAction $resolveReceptionContextAction,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
        private readonly AttachUploadedAttachmentsAction $attachUploadedAttachmentsAction,
    ) {}

    /**
     * 追加访客消息并刷新会话的最近消息和访客回复等待状态。
     *
     * 文本与附件分开生成：文本（如非空）单独成消息，每个附件再各自成一条消息，
     * 与客服端 AppendTeammateMessageAction 的多附件交互保持一致。
     *
     * 提供 $clientMsgId 时，会在事务前后做幂等校验：若该会话已存在同 client_msg_id 的消息，
     * 则跳过整次发送，直接返回当前状态，保证弱网重发不会重复落库。
     *
     * @param  list<string>  $attachmentIds
     * @param  array<string, string>|null  $queryParams
     */
    public function handle(
        string $channelCode,
        ?string $sessionToken,
        string $content,
        ?ConversationEntryMode $entryMode = null,
        ?array $visitorEnvironment = null,
        array $attachmentIds = [],
        ?string $userToken = null,
        ?array $queryParams = null,
        ?string $clientMsgId = null,
        ?string $quotedMessageId = null,
    ): ReceptionStateData {
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

        $context = $this->resolveReceptionContextAction->handle(
            $channelCode,
            $sessionToken,
            $entryMode,
            $visitorEnvironment,
            $userToken,
            $queryParams,
        );
        /** @var Conversation $conversation */
        $conversation = $context['conversation'];
        $conversation->loadMissing('contact');
        assert($conversation->contact !== null, 'conversation must have a contact');
        $visitorSenderName = (string) $conversation->contact->name;

        if ($clientMsgId !== null && $this->messageExistsForClientId($conversation->id, $clientMsgId)) {
            return ReceptionStateBuilder::build($context['channel'], $conversation, $context['session_token']);
        }

        $resolvedQuotedMessageId = $this->resolveQuotedMessageId($conversation->id, $quotedMessageId);

        try {
            DB::transaction(function () use ($conversation, $content, $attachmentIds, $context, $clientMsgId, $resolvedQuotedMessageId, $visitorSenderName): void {
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
                        'role' => MessageRole::Visitor,
                        'sender_name' => $visitorSenderName,
                        'kind' => MessageKind::Text,
                        'content' => $content,
                        'content_locale' => null,
                        'payload' => null,
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
                        'role' => MessageRole::Visitor,
                        'sender_name' => $visitorSenderName,
                        'kind' => $kind,
                        'content' => null,
                        'payload' => null,
                        // 只把 client_msg_id 落在批次首条消息上，作为整个 send 操作的幂等键。
                        'client_msg_id' => $firstClientMsgIdConsumed ? null : $clientMsgId,
                        'quoted_message_id' => $firstClientMsgIdConsumed ? null : $resolvedQuotedMessageId,
                    ]);
                    $firstClientMsgIdConsumed = $firstClientMsgIdConsumed || $clientMsgId !== null;

                    $attached = $this->attachUploadedAttachmentsAction->handle(
                        attachable: $attachmentMessage,
                        attachmentId: (string) $attachment->id,
                        workspaceId: (string) $conversation->workspace_id,
                        sessionToken: $context['session_token'],
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
                $previewSource = $content !== '' ? $content : $lastMessage->attachmentPreview();

                $conversation->update([
                    'last_message_at' => now(),
                    'last_message_preview' => Str::limit($previewSource, self::PREVIEW_LENGTH, ''),
                    'waiting_for_visitor_reply' => false,
                    // 访客发新消息 == 隐式确认已看完客服/AI 的所有未读回复。
                    'unread_agent_message_count' => 0,
                ]);

                Conversation::query()
                    ->whereKey($conversation->id)
                    ->increment('unread_visitor_message_count', $messages->count());
            });
        } catch (UniqueConstraintViolationException) {
            // 并发请求带相同 client_msg_id 时另一路抢先落库；按幂等约定返回当前状态即可。
            if ($clientMsgId !== null) {
                return ReceptionStateBuilder::build($context['channel'], $conversation->fresh(), $context['session_token']);
            }

            throw new \RuntimeException('Unexpected unique constraint violation on conversation message insert.');
        }

        $conversation = $conversation->fresh();

        // 给消费端足够的元数据按 seq_no 增量合并，client_msg_id 帮访客端识别 echo 来源。
        $latestMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('seq_no')
            ->first();
        $meta = $latestMessage !== null
            ? [
                'message_id' => (string) $latestMessage->id,
                'seq_no' => (int) $latestMessage->seq_no,
                'client_msg_id' => $latestMessage->client_msg_id,
            ]
            : [];

        $this->realtimeNotifier->conversationChanged(
            $conversation,
            'visitor_message_created',
            meta: $meta,
            channel: $context['channel'],
        );

        $this->dispatchSubjectGenerationIfNeeded($conversation, $content);

        return ReceptionStateBuilder::build($context['channel'], $conversation, $context['session_token']);
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
     * 判断该会话下是否已经存在带相同 client_msg_id 的消息。
     */
    private function messageExistsForClientId(string $conversationId, string $clientMsgId): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('client_msg_id', $clientMsgId)
            ->exists();
    }

    /**
     * 解析当前会话内的引用消息 ID。
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
}
