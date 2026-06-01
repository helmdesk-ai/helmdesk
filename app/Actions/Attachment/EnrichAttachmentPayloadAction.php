<?php

namespace App\Actions\Attachment;

use App\Models\Attachment;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为消息 payload 中的附件快照补充当前访问地址。
 */
class EnrichAttachmentPayloadAction
{
    use AsAction;

    /**
     * 查询附件并把下载地址、预览地址合并回 payload。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $items = $payload['attachments'] ?? null;
        if (! is_array($items)) {
            return $payload;
        }

        $ids = collect($items)
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $attachments = Attachment::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $payload['attachments'] = collect($items)
            ->map(function (mixed $item) use ($attachments): mixed {
                if (! is_array($item) || ! isset($item['id'])) {
                    return $item;
                }

                $attachment = $attachments->get((string) $item['id']);
                if (! $attachment) {
                    return $item;
                }

                return array_merge($item, [
                    'url' => $attachment->full_url,
                    'preview_url' => $attachment->preview_url,
                ]);
            })
            ->values()
            ->all();

        return $payload;
    }
}
