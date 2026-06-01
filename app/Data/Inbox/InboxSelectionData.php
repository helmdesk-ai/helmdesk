<?php

namespace App\Data\Inbox;

use App\Data\Contact\ContactStitchedTimelineData;
use App\Data\Conversation\ConversationContactSummaryData;
use App\Data\Conversation\ConversationSummaryData;
use Spatie\LaravelData\Data;

/**
 * 收件箱当前选中的会话。
 * 由收件箱页 props 返回，前端用它决定列表高亮和右侧详情内容。
 */
class InboxSelectionData extends Data
{
    public function __construct(
        public ConversationSummaryData $conversation,
        public ?ConversationContactSummaryData $contact,
        public ?InboxContactProfileData $contact_profile,
        public ContactStitchedTimelineData $stitched_timeline,
        public bool $can_reply,
        public bool $can_claim,
        public bool $can_transfer_to_teammate,
        public bool $can_release_to_ai,
        public bool $release_to_ai_will_use_ai,
        public bool $can_close,
        public bool $can_reopen,
        public bool $can_translate_messages,
        public ?string $reply_visitor_locale = null,
    ) {}
}
