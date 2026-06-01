<?php

namespace App\Data\Inbox;

use Spatie\LaravelData\Data;

/**
 * 收件箱聊天记录搜索的单条匹配结果。
 */
class InboxMessageSearchResultData extends Data
{
    public function __construct(
        public string $id,
        public string $conversation_id,
        public ?string $role,
        public ?string $role_label,
        public ?string $kind,
        public ?string $sender_name,
        public ?string $content,
        public string $matched_content,
        public string $occurred_at,
    ) {}
}
