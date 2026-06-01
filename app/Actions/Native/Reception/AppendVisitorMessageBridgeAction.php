<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\AppendVisitorMessageAction;
use App\Data\Reception\NativeReceptionStateData;
use App\Enums\ConversationEntryMode;
use App\Models\Conversation;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Native bridge 入口：追加访客消息。
 */
class AppendVisitorMessageBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责追加访客消息的业务 Action。
     */
    public function __construct(
        private readonly AppendVisitorMessageAction $appendVisitorMessageAction,
    ) {}

    /**
     * 将 Go 传入的小类型消息参数转换后追加访客消息。
     *
     * @param  array{locale?: string|null, timezone?: string|null, country?: string|null, city?: string|null}|null  $visitorEnvironment
     * @param  array<int, mixed>  $attachmentIds
     */
    public function handle(
        string $channelCode,
        ?string $sessionToken,
        string $content,
        ?string $entryMode = null,
        ?array $visitorEnvironment = null,
        array $attachmentIds = [],
        ?string $userToken = null,
        ?array $queryParams = null,
        ?string $clientMsgId = null,
        ?string $quotedMessageId = null,
    ): NativeReceptionStateData {
        $state = $this->appendVisitorMessageAction->handle(
            channelCode: $channelCode,
            sessionToken: $sessionToken,
            content: $content,
            entryMode: $this->entryMode($entryMode),
            visitorEnvironment: $visitorEnvironment,
            attachmentIds: $this->attachmentIds($attachmentIds),
            userToken: $userToken,
            queryParams: $this->normalizeQueryParams($queryParams),
            clientMsgId: $this->normalizeOptionalId($clientMsgId),
            quotedMessageId: $this->normalizeOptionalId($quotedMessageId),
        );

        $conversation = Conversation::query()->findOrFail($state->conversation_id);

        return NativeReceptionStateData::fromReceptionState($state, $conversation);
    }

    /**
     * 把 Go 传入的可选 ID 标准化为非空字符串或 null，避免空字符串误进业务层。
     */
    private function normalizeOptionalId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * 将 Native bridge 的字符串入口模式转换为业务层枚举。
     */
    private function entryMode(?string $entryMode): ConversationEntryMode
    {
        $entryMode = trim($entryMode ?? '');
        if ($entryMode === '') {
            return ConversationEntryMode::Standalone;
        }

        return ConversationEntryMode::tryFrom($entryMode)
            ?? throw ValidationException::withMessages(['entry_mode' => __('validation.in', ['attribute' => 'entry_mode'])]);
    }

    /**
     * 过滤并清洗 Go 传入的附件 ID 列表。
     *
     * @param  array<int, mixed>  $attachmentIds
     * @return list<string>
     */
    private function attachmentIds(array $attachmentIds): array
    {
        return collect($attachmentIds)
            ->filter(fn (mixed $attachmentId): bool => is_string($attachmentId) && trim($attachmentId) !== '')
            ->map(fn (string $attachmentId): string => trim($attachmentId))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $queryParams
     * @return array<string, string>
     */
    private function normalizeQueryParams(?array $queryParams): array
    {
        if ($queryParams === null) {
            return [];
        }

        $normalized = [];
        foreach ($queryParams as $key => $value) {
            if (is_string($key) && $key !== '' && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
