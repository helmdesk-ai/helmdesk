<?php

namespace App\Services\Reception;

use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Data\Channel\Web\ChannelWebVisitorInterfaceSettingsData;
use App\Data\Conversation\QuotedMessageData;
use App\Data\Reception\ReceptionAttachmentData;
use App\Data\Reception\ReceptionMessageData;
use App\Data\Reception\ReceptionStateData;
use App\Enums\Channel\Web\WebChannelVisitorIdentityMode;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlanVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 组装访客接待窗口的当前状态。
 *
 * AI 头像 / 显示名解析顺序：
 * - unified_service：直接读访客界面设置的 service_display_name + service_avatar_id
 * - actual_receptionist：读 PlanVersion snapshot 的 persona_config.display_name；avatar 暂为空
 *   （Plan persona 当前没有 avatar 字段；待 Persona 数据结构扩展后再回填）
 */
class ReceptionStateBuilder
{
    private const MAX_MESSAGES = 500;

    /**
     * 组装访客端接待状态数据。
     */
    public static function build(Channel $channel, Conversation $conversation, string $sessionToken): ReceptionStateData
    {
        // Web 渠道带访客界面设置；非 Web 渠道（如 Telegram）无此设置，身份模式回退到 actual_receptionist。
        $visitorInterface = self::webVisitorInterface($channel);
        $visitorIdentityMode = $visitorInterface?->visitor_identity_mode
            ?? WebChannelVisitorIdentityMode::ActualReceptionist;
        [$assistantName, $assistantAvatarUrl] = self::channelMessageIdentity($channel, $conversation);
        $conversationIds = self::historyConversationIds($channel, $conversation);

        $messages = ConversationMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->with(['senderUser', 'attachments', 'quotedMessage.attachments'])
            ->whereIn('kind', [MessageKind::Text, MessageKind::Image, MessageKind::File])
            ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_MESSAGES)
            ->get()
            ->sortBy([
                ['created_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $workspaceNicknames = self::workspaceNicknames($conversation, $messages);

        $entries = $messages
            ->map(function (ConversationMessage $message) use ($visitorIdentityMode, $assistantName, $assistantAvatarUrl, $workspaceNicknames): ReceptionMessageData {
                $quotedMessage = self::quotedMessageData($message);
                [$senderName, $senderAvatarUrl] = self::senderMessageIdentity(
                    $visitorIdentityMode,
                    $message,
                    $assistantName,
                    $assistantAvatarUrl,
                    $workspaceNicknames,
                );

                return match ($message->role) {
                    MessageRole::Ai => ReceptionMessageData::fromModel(
                        $message,
                        senderName: $senderName,
                        senderAvatarUrl: $senderAvatarUrl,
                        quotedMessage: $quotedMessage,
                    ),
                    MessageRole::Teammate => ReceptionMessageData::fromModel(
                        $message,
                        senderName: $senderName,
                        senderAvatarUrl: $senderAvatarUrl,
                        quotedMessage: $quotedMessage,
                    ),
                    default => ReceptionMessageData::fromModel($message, quotedMessage: $quotedMessage),
                };
            })
            ->values()
            ->all();

        return new ReceptionStateData(
            session_token: $sessionToken,
            conversation_id: (string) $conversation->id,
            status: $conversation->status->value,
            assistant_name: $assistantName,
            assistant_avatar_url: $assistantAvatarUrl,
            messages: $entries,
        );
    }

    /**
     * 生成访客端引用块需要的被引用消息快照。
     */
    private static function quotedMessageData(ConversationMessage $message): ?QuotedMessageData
    {
        $quoted = $message->quotedMessage;
        if (! $quoted instanceof ConversationMessage) {
            return null;
        }

        return new QuotedMessageData(
            id: (string) $quoted->id,
            role: $quoted->role->value,
            kind: $quoted->kind->value,
            sender_name: (string) $quoted->sender_name,
            preview: self::quotedMessagePreview($quoted),
            content: ! $quoted->isRecalled() && is_string($quoted->content) ? $quoted->content : null,
            attachments: $quoted->isRecalled()
                ? []
                : $quoted->attachments
                    ->map(fn (Attachment $attachment): array => ReceptionAttachmentData::fromModel($attachment)->toArray())
                    ->values()
                    ->all(),
            recalled_at: $quoted->recalled_at?->toIso8601String(),
        );
    }

    /**
     * 生成引用块中的单行预览。
     */
    private static function quotedMessagePreview(ConversationMessage $message): string
    {
        if ($message->isRecalled()) {
            return __('conversation.message_recalled_placeholder');
        }

        if (is_string($message->content) && trim($message->content) !== '') {
            return str($message->content)->squish()->limit(120, '')->toString();
        }

        return match ($message->kind) {
            MessageKind::Image => __('conversation.message_kinds.image'),
            MessageKind::File => __('conversation.message_kinds.file'),
            default => __('conversation.empty_content'),
        };
    }

    /**
     * 解析渠道默认展示给访客的接待身份。
     *
     * Web 渠道在 unified_service 模式下读访客界面设置的 service_display_name + 头像；
     * 其它模式与非 Web 渠道（如 Telegram，无访客界面设置）依次读会话锁定版本、渠道当前部署版本的
     * persona display_name，再回退到默认 AI 助手文案，头像为空。
     *
     * @return array{0: string, 1: ?string}
     */
    public static function channelMessageIdentity(
        Channel $channel,
        Conversation $conversation,
    ): array {
        $visitorInterface = self::webVisitorInterface($channel);
        if ($visitorInterface !== null
            && $visitorInterface->visitor_identity_mode === WebChannelVisitorIdentityMode::UnifiedService) {
            return [
                filled($visitorInterface->service_display_name) ? (string) $visitorInterface->service_display_name : $channel->name,
                Attachment::findUrl($visitorInterface->service_avatar_id),
            ];
        }

        $displayName = self::resolvePlanPersonaDisplayName($conversation, $channel)
            ?? (string) __('channel.defaults.assistant_name');

        return [$displayName, null];
    }

    /**
     * 取 Web 渠道的访客界面设置；非 Web 渠道（如 Telegram）无此设置，返回 null。
     */
    private static function webVisitorInterface(Channel $channel): ?ChannelWebVisitorInterfaceSettingsData
    {
        $settings = $channel->settings;

        return $settings instanceof ChannelWebSettingsData ? $settings->visitor_interface : null;
    }

    /**
     * 解析单条消息在访客侧展示的发送者身份。
     *
     * @param  array<string, string>  $workspaceNicknames
     * @return array{0: ?string, 1: ?string}
     */
    private static function senderMessageIdentity(
        WebChannelVisitorIdentityMode $mode,
        ConversationMessage $message,
        string $assistantName,
        ?string $assistantAvatarUrl,
        array $workspaceNicknames,
    ): array {
        if ($mode === WebChannelVisitorIdentityMode::UnifiedService) {
            return [$assistantName, $assistantAvatarUrl];
        }

        if ($message->role === MessageRole::Ai) {
            return [$assistantName, $assistantAvatarUrl];
        }

        if ($message->role === MessageRole::Teammate) {
            $senderUserId = filled($message->sender_user_id) ? (string) $message->sender_user_id : null;
            $nickname = $senderUserId ? ($workspaceNicknames[$senderUserId] ?? null) : null;

            return [
                filled($nickname) ? $nickname : $message->senderUser?->name,
                $message->senderUser?->avatar,
            ];
        }

        return [null, null];
    }

    /**
     * 从会话锁定的 PlanVersion（缺失时回退到渠道当前生效版本）snapshot 中读取 persona display_name。
     */
    private static function resolvePlanPersonaDisplayName(Conversation $conversation, Channel $channel): ?string
    {
        $versionId = $conversation->reception_plan_version_id
            ?? app(ChannelActivePlanVersionResolver::class)->currentVersionForChannel($channel)?->id;

        if (! filled($versionId)) {
            return null;
        }

        $version = ReceptionPlanVersion::query()->find($versionId);

        if ($version === null) {
            return null;
        }

        $snapshot = $version->snapshot_config;
        $persona = $snapshot['persona_config'] ?? [];
        $displayName = $persona['display_name'] ?? null;

        return is_string($displayName) && filled($displayName) ? $displayName : null;
    }

    /**
     * 查询消息发送者在系统内的昵称。
     *
     * @param  Collection<int, ConversationMessage>  $messages
     * @return array<string, string>
     */
    private static function workspaceNicknames(Conversation $conversation, Collection $messages): array
    {
        $userIds = $messages
            ->flatMap(fn (ConversationMessage $message): array => [
                $message->sender_user_id,
                $message->quotedMessage?->sender_user_id,
            ])
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('nickname')
            ->pluck('nickname', 'id')
            ->mapWithKeys(fn ($nickname, $userId): array => [(string) $userId => (string) $nickname])
            ->all();
    }

    /**
     * 取同一访客在当前渠道下的已有会话 ID。
     *
     * @return list<string>
     */
    private static function historyConversationIds(Channel $channel, Conversation $conversation): array
    {
        if ($conversation->contact_id === null || $conversation->channel_id === null) {
            return [(string) $conversation->id];
        }

        return Conversation::query()
            ->where('contact_id', $conversation->contact_id)
            ->where('channel_id', $channel->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }
}
