<?php

namespace App\Data\Conversation;

use App\Data\EnumOptionData;
use App\Data\Reception\Plan\ReceptionPlanOptionData;
use App\Data\SimplePaginationData;
use App\Data\Tag\TagOptionData;
use App\Data\User\UserOptionData;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\ConversationVisitorReplyStatus;
use Spatie\LaravelData\Data;

/**
 * 会话页面 props。
 */
class ShowConversationListPagePropsData extends Data
{
    public function __construct(
        /** @var ListConversationItemData[] */
        public array $conversation_list,
        public SimplePaginationData $conversation_list_pagination,
        /** @var EnumOptionData[] */
        public array $status_options,
        /** @var EnumOptionData[] */
        public array $inbox_status_options,
        /** @var EnumOptionData[] */
        public array $visitor_reply_status_options,
        /** @var EnumOptionData[] */
        public array $source_options,
        /** @var EnumOptionData[] */
        public array $tag_match_mode_options,
        public ?string $search,
        public ?ConversationStatus $current_status,
        public ?ConversationInboxStatus $current_inbox_status,
        public ?ConversationVisitorReplyStatus $current_visitor_reply_status,
        public ?string $current_assigned_user_id,
        public ?string $current_reception_plan_id,
        /** @var TagOptionData[] */
        public array $available_contact_tags,
        /** @var UserOptionData[] */
        public array $teammate_options,
        /** @var ReceptionPlanOptionData[] */
        public array $reception_plan_options,
    ) {}
}
