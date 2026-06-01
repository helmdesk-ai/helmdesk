<?php

namespace App\Actions\Contact;

use App\Actions\Attachment\EnrichAttachmentPayloadAction;
use App\Actions\Conversation\ApplyConversationTimelineEventDisplayScopeAction;
use App\Actions\Conversation\BuildConversationEventDisplayAction;
use App\Actions\Conversation\BuildConversationTimelineUserMapAction;
use App\Actions\Conversation\BuildQuotedMessageMapAction;
use App\Actions\Conversation\ResolveRecalledMessageContentAction;
use App\Data\Contact\ContactStitchedTimelineData;
use App\Data\Contact\ContactTimelineEntryData;
use App\Data\Conversation\ConversationSummaryData;
use App\Enums\ConversationEventType;
use App\Enums\ConversationTimelineEntryType;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationTimelineEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载联系人会话时间线。
 */
class ShowContactConversationTimelineAction
{
    use AsAction;

    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /**
     * 返回联系人所有会话拼接后的时间线数据。
     *
     * viewer 用于决定已撤回消息原文是否随响应下发。
     */
    public function handle(
        Contact $contact,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?User $viewer = null,
        ?string $before = null,
        ?string $after = null,
        ?ConversationTimelineEntryType $anchorType = null,
        ?string $anchorId = null,
    ): ContactStitchedTimelineData {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $conversations = Conversation::query()
            ->where('workspace_id', $contact->workspace_id)
            ->where('contact_id', $contact->id)
            ->with(['channel', 'tags'])
            ->withCount(['messages as display_message_count' => Conversation::displayMessageCountQuery()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($conversations->isEmpty()) {
            return new ContactStitchedTimelineData(
                contact_id: (string) $contact->id,
                conversations: [],
                entries: [],
                previous_cursor: null,
                next_cursor: null,
            );
        }

        [$timelineRows, $previousCursor, $nextCursor, $anchorEntryId] = $this->loadTimelineRows(
            contact: $contact,
            perPage: $perPage,
            before: $before,
            after: $after,
            anchorType: $anchorType,
            anchorId: $anchorId,
        );

        return new ContactStitchedTimelineData(
            contact_id: (string) $contact->id,
            conversations: $conversations
                ->map(fn (Conversation $conversation) => ConversationSummaryData::fromModel($conversation))
                ->all(),
            entries: $this->buildEntries($contact, $timelineRows, $viewer),
            previous_cursor: $previousCursor,
            next_cursor: $nextCursor,
            anchor_entry_id: $anchorEntryId,
        );
    }

    /**
     * 加载默认、游标或锚点窗口的时间线索引行。
     *
     * @return array{0: Collection<int, object>, 1: ?string, 2: ?string, 3: ?string}
     */
    private function loadTimelineRows(
        Contact $contact,
        int $perPage,
        ?string $before,
        ?string $after,
        ?ConversationTimelineEntryType $anchorType,
        ?string $anchorId,
    ): array {
        if ($anchorType !== null && $anchorId !== null) {
            return $this->loadAnchorRows($contact, $perPage, $anchorType, $anchorId);
        }

        if ($before !== null) {
            return $this->loadBeforeRows($contact, $perPage, $before);
        }

        if ($after !== null) {
            return $this->loadAfterRows($contact, $perPage, $after);
        }

        return $this->loadLatestRows($contact, $perPage);
    }

    /**
     * 加载最新时间线窗口。
     *
     * @return array{0: Collection<int, object>, 1: ?string, 2: ?string, 3: ?string}
     */
    private function loadLatestRows(Contact $contact, int $perPage): array
    {
        $rows = $this->timelineQuery($contact)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasPrevious = $rows->count() > $perPage;
        $pageRows = $rows->take($perPage)->reverse()->values();

        return [
            $pageRows,
            $hasPrevious && $pageRows->isNotEmpty() ? $this->encodeCursor($pageRows->first()) : null,
            null,
            null,
        ];
    }

    /**
     * 加载指定游标之前的时间线窗口。
     *
     * @return array{0: Collection<int, object>, 1: ?string, 2: ?string, 3: ?string}
     */
    private function loadBeforeRows(Contact $contact, int $perPage, string $cursor): array
    {
        $decoded = $this->decodeCursor($cursor);
        $rows = $this->timelineQuery($contact)
            ->where(function ($query) use ($decoded): void {
                $query
                    ->where('occurred_at', '<', $decoded['occurred_at'])
                    ->orWhere(function ($query) use ($decoded): void {
                        $query
                            ->where('occurred_at', $decoded['occurred_at'])
                            ->where('id', '<', $decoded['id']);
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasPrevious = $rows->count() > $perPage;
        $pageRows = $rows->take($perPage)->reverse()->values();

        return [
            $pageRows,
            $hasPrevious && $pageRows->isNotEmpty() ? $this->encodeCursor($pageRows->first()) : null,
            $pageRows->isNotEmpty() ? $this->encodeCursor($pageRows->last()) : null,
            null,
        ];
    }

    /**
     * 加载指定游标之后的时间线窗口。
     *
     * @return array{0: Collection<int, object>, 1: ?string, 2: ?string, 3: ?string}
     */
    private function loadAfterRows(Contact $contact, int $perPage, string $cursor): array
    {
        $decoded = $this->decodeCursor($cursor);
        $rows = $this->timelineQuery($contact)
            ->where(function ($query) use ($decoded): void {
                $query
                    ->where('occurred_at', '>', $decoded['occurred_at'])
                    ->orWhere(function ($query) use ($decoded): void {
                        $query
                            ->where('occurred_at', $decoded['occurred_at'])
                            ->where('id', '>', $decoded['id']);
                    });
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get();

        $hasNext = $rows->count() > $perPage;
        $pageRows = $rows->take($perPage)->values();

        return [
            $pageRows,
            $pageRows->isNotEmpty() ? $this->encodeCursor($pageRows->first()) : null,
            $hasNext && $pageRows->isNotEmpty() ? $this->encodeCursor($pageRows->last()) : null,
            null,
        ];
    }

    /**
     * 加载锚点附近的时间线窗口。
     *
     * @return array{0: Collection<int, object>, 1: ?string, 2: ?string, 3: ?string}
     */
    private function loadAnchorRows(
        Contact $contact,
        int $perPage,
        ConversationTimelineEntryType $anchorType,
        string $anchorId,
    ): array {
        $anchor = $this->timelineQuery($contact)
            ->where('entry_type', $anchorType->value)
            ->where('entry_id', $anchorId)
            ->first() ?? throw (new ModelNotFoundException)->setModel(ConversationTimelineEntry::class, [$anchorId]);

        $beforeCount = intdiv($perPage - 1, 2);
        $afterCount = $perPage - 1 - $beforeCount;

        $olderRows = $this->timelineQuery($contact)
            ->where(function ($query) use ($anchor): void {
                $query
                    ->where('occurred_at', '<', $anchor->occurred_at)
                    ->orWhere(function ($query) use ($anchor): void {
                        $query
                            ->where('occurred_at', $anchor->occurred_at)
                            ->where('id', '<', $anchor->id);
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($beforeCount + 1)
            ->get();

        $newerRows = $this->timelineQuery($contact)
            ->where(function ($query) use ($anchor): void {
                $query
                    ->where('occurred_at', '>', $anchor->occurred_at)
                    ->orWhere(function ($query) use ($anchor): void {
                        $query
                            ->where('occurred_at', $anchor->occurred_at)
                            ->where('id', '>', $anchor->id);
                    });
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($afterCount + 1)
            ->get();

        $hasPrevious = $olderRows->count() > $beforeCount;
        $hasNext = $newerRows->count() > $afterCount;
        $olderPageRows = $olderRows->take($beforeCount)->reverse()->values();
        $newerPageRows = $newerRows->take($afterCount)->values();
        $pageRows = $olderPageRows
            ->push($anchor)
            ->concat($newerPageRows)
            ->values();

        return [
            $pageRows,
            $hasPrevious && $pageRows->isNotEmpty() ? $this->encodeCursor($pageRows->first()) : null,
            $hasNext && $pageRows->isNotEmpty() ? $this->encodeCursor($pageRows->last()) : null,
            (string) $anchor->entry_id,
        ];
    }

    /**
     * 生成联系人时间线索引基础查询。
     */
    private function timelineQuery(Contact $contact): Builder
    {
        return DB::table('conversation_timeline_entries')
            ->where('workspace_id', $contact->workspace_id)
            ->where('contact_id', $contact->id)
            ->where(function (Builder $query): void {
                $query
                    ->where('entry_type', ConversationTimelineEntryType::Message->value)
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('entry_type', ConversationTimelineEntryType::Event->value)
                            ->whereExists(function (Builder $query): void {
                                $query
                                    ->selectRaw('1')
                                    ->from('conversation_events')
                                    ->whereColumn('conversation_events.id', 'conversation_timeline_entries.entry_id');

                                ApplyConversationTimelineEventDisplayScopeAction::run($query);
                            });
                    });
            });
    }

    /**
     * 将时间线索引行补全为前端展示条目。
     *
     * @return ContactTimelineEntryData[]
     */
    private function buildEntries(Contact $contact, Collection $timelineRows, ?User $viewer): array
    {
        if ($timelineRows->isEmpty()) {
            return [];
        }

        $messageIds = $timelineRows
            ->where('entry_type', ConversationTimelineEntryType::Message->value)
            ->pluck('entry_id')
            ->all();
        $eventIds = $timelineRows
            ->where('entry_type', ConversationTimelineEntryType::Event->value)
            ->pluck('entry_id')
            ->all();

        $rowsByKey = collect();

        if ($messageIds !== []) {
            DB::table('conversation_messages')
                ->selectRaw("
                    conversation_messages.id,
                    conversation_messages.conversation_id,
                    conversation_messages.created_at as occurred_at,
                    'message' as type,
                    conversation_messages.role,
                    conversation_messages.kind,
                    null as event_type,
                    conversation_messages.sender_user_id as actor_user_id,
                    COALESCE(NULLIF(conversation_messages.sender_name, ''), sender_users.name) as sender_name,
                    sender_users.avatar as sender_avatar_url,
                    conversation_messages.content,
                    conversation_messages.content_locale,
                    conversation_messages.payload,
                    conversation_messages.seq_no,
                    conversation_messages.delivery_status,
                    conversation_messages.quoted_message_id,
                    conversation_messages.recalled_at
                ")
                ->leftJoin('users as sender_users', 'sender_users.id', '=', 'conversation_messages.sender_user_id')
                ->where('conversation_messages.workspace_id', $contact->workspace_id)
                ->whereIn('conversation_messages.id', $messageIds)
                ->get()
                ->each(fn (object $row) => $rowsByKey->put(ConversationTimelineEntryType::Message->value.':'.$row->id, $row));
        }

        if ($eventIds !== []) {
            $events = DB::table('conversation_events')
                ->selectRaw("
                    id,
                    conversation_id,
                    created_at as occurred_at,
                    'event' as type,
                    null as role,
                    null as kind,
                    type as event_type,
                    actor_user_id,
                    null as sender_name,
                    null as sender_avatar_url,
                    null as content,
                    null as content_locale,
                    payload,
                    null as seq_no,
                    null as delivery_status,
                    null as quoted_message_id,
                    null as recalled_at
                ")
                ->where('workspace_id', $contact->workspace_id)
                ->whereIn('id', $eventIds);

            ApplyConversationTimelineEventDisplayScopeAction::run($events);

            $events
                ->get()
                ->each(fn (object $row) => $rowsByKey->put(ConversationTimelineEntryType::Event->value.':'.$row->id, $row));
        }

        $pageRows = $timelineRows
            ->map(fn (object $timelineRow) => $rowsByKey->get($timelineRow->entry_type.':'.$timelineRow->entry_id))
            ->values();

        $quotedMessages = BuildQuotedMessageMapAction::run($pageRows, (string) $contact->workspace_id);
        $userNamesById = BuildConversationTimelineUserMapAction::run($pageRows, (string) $contact->workspace_id);

        return $pageRows
            ->map(function (object $row) use ($viewer, $quotedMessages, $userNamesById): ContactTimelineEntryData {
                $isMessage = $row->type === ConversationTimelineEntryType::Message->value;
                $isRecalled = $isMessage && $row->recalled_at !== null;
                $eventDisplay = $isMessage ? null : BuildConversationEventDisplayAction::run($row, $userNamesById);

                return new ContactTimelineEntryData(
                    id: (string) $row->id,
                    conversation_id: (string) $row->conversation_id,
                    type: (string) $row->type,
                    subtype: $this->buildSubtype($row),
                    subtype_label: $this->buildSubtypeLabel($row),
                    role: $isMessage && $row->role !== null ? (string) $row->role : null,
                    kind: $isMessage && $row->kind !== null ? (string) $row->kind : null,
                    event_type: $row->type === ConversationTimelineEntryType::Event->value && $row->event_type !== null ? (string) $row->event_type : null,
                    actor_user_id: $row->actor_user_id ? (string) $row->actor_user_id : null,
                    sender_name: $isMessage && $row->sender_name !== null ? (string) $row->sender_name : null,
                    sender_avatar_url: $row->sender_avatar_url ? (string) $row->sender_avatar_url : null,
                    content: ! $isRecalled && $row->content !== null ? (string) $row->content : null,
                    content_locale: ! $isRecalled && $row->content_locale !== null ? (string) $row->content_locale : null,
                    payload: $isMessage && ! $isRecalled ? $this->normalizePayload($row->payload) : null,
                    occurred_at: Carbon::parse((string) $row->occurred_at)->toIso8601String(),
                    seq_no: $isMessage && $row->seq_no !== null ? (int) $row->seq_no : null,
                    delivery_status: $isMessage && $row->delivery_status !== null ? (string) $row->delivery_status : null,
                    quoted_message_id: $isMessage && $row->quoted_message_id !== null ? (string) $row->quoted_message_id : null,
                    quoted_message: $isMessage && $row->quoted_message_id !== null
                        ? ($quotedMessages[(string) $row->quoted_message_id] ?? null)
                        : null,
                    recalled_at: $isMessage && $row->recalled_at !== null ? Carbon::parse((string) $row->recalled_at)->toIso8601String() : null,
                    recalled_content: ResolveRecalledMessageContentAction::run($row, $isRecalled, $viewer),
                    event_display: $eventDisplay,
                );
            })
            ->values()
            ->all();
    }

    /**
     * 生成前端区分消息和事件样式的子类型标识。
     */
    private function buildSubtype(object $row): string
    {
        if ($row->type === ConversationTimelineEntryType::Message->value) {
            return 'message:'.(string) $row->role.':'.(string) $row->kind;
        }

        return 'event:'.(string) $row->event_type;
    }

    /**
     * 生成人类可读的消息或事件类型标签。
     */
    private function buildSubtypeLabel(object $row): string
    {
        if ($row->type === ConversationTimelineEntryType::Message->value) {
            return MessageRole::from((string) $row->role)->label().' · '.MessageKind::from((string) $row->kind)->label();
        }

        return ConversationEventType::from((string) $row->event_type)->label();
    }

    /**
     * 将数据库中的 payload 解码为数组并补充附件地址。
     *
     * @return array<string, mixed>|null
     */
    private function normalizePayload(mixed $payload): ?array
    {
        $decoded = is_array($payload)
            ? $payload
            : (is_string($payload) && $payload !== '' ? json_decode($payload, true) : null);

        if (! is_array($decoded)) {
            return null;
        }

        return EnrichAttachmentPayloadAction::run($decoded);
    }

    /**
     * 编码 keyset 游标。
     */
    private function encodeCursor(object $row): string
    {
        return base64_encode(json_encode([
            'occurred_at' => (string) $row->occurred_at,
            'id' => (string) $row->id,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 解码 keyset 游标。
     *
     * @return array{occurred_at: string, id: string}
     */
    private function decodeCursor(string $cursor): array
    {
        return json_decode(base64_decode($cursor), true, 512, JSON_THROW_ON_ERROR);
    }
}
