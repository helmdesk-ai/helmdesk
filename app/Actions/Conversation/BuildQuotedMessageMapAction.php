<?php

namespace App\Actions\Conversation;

use App\Actions\Attachment\EnrichAttachmentPayloadAction;
use App\Data\Conversation\QuotedMessageData;
use App\Enums\MessageKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为时间线中出现的 quoted_message_id 构造轻量引用快照。
 *
 * 会话详情和联系人时间线两个 Action 都需要把 quoted_message_id 映射成
 * 可展示的引用预览，逻辑（取 sender / preview / attachments / recalled_at）一致。
 */
class BuildQuotedMessageMapAction
{
    use AsAction;

    /**
     * 输入：已经拉出来的时间线行集合，输出按 message id 索引的引用快照表。
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, QuotedMessageData>
     */
    public function handle(Collection $rows): array
    {
        $quotedIds = $rows
            ->pluck('quoted_message_id')
            ->filter()
            ->unique()
            ->values();

        if ($quotedIds->isEmpty()) {
            return [];
        }

        return DB::table('conversation_messages')
            ->select('id', 'role', 'kind', 'content', 'payload', 'recalled_at', 'sender_name')
            ->whereIn('id', $quotedIds->all())
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->id => new QuotedMessageData(
                    id: (string) $row->id,
                    role: (string) $row->role,
                    kind: (string) $row->kind,
                    sender_name: (string) $row->sender_name,
                    preview: $this->buildPreview($row),
                    content: $row->recalled_at === null && is_string($row->content) ? (string) $row->content : null,
                    attachments: $row->recalled_at === null ? $this->attachments($row->payload) : [],
                    recalled_at: $row->recalled_at !== null ? Carbon::parse((string) $row->recalled_at)->toIso8601String() : null,
                ),
            ])
            ->all();
    }

    /**
     * 生成引用块中的单行预览。
     */
    private function buildPreview(object $row): string
    {
        if ($row->recalled_at !== null) {
            return __('conversation.message_recalled_placeholder');
        }

        if (is_string($row->content) && trim($row->content) !== '') {
            return str((string) $row->content)->squish()->limit(120, '')->toString();
        }

        return match (MessageKind::tryFrom((string) $row->kind)) {
            MessageKind::Image => __('conversation.message_kinds.image'),
            MessageKind::File => __('conversation.message_kinds.file'),
            default => __('conversation.empty_content'),
        };
    }

    /**
     * 返回引用消息中可点击的附件快照。
     *
     * @return list<array<string, mixed>>
     */
    private function attachments(mixed $payload): array
    {
        $decoded = is_array($payload)
            ? $payload
            : (is_string($payload) && $payload !== '' ? json_decode($payload, true) : null);

        if (! is_array($decoded)) {
            return [];
        }

        $enriched = EnrichAttachmentPayloadAction::run($decoded);
        $attachments = $enriched['attachments'] ?? null;

        return is_array($attachments) ? array_values($attachments) : [];
    }
}
