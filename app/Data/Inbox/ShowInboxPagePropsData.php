<?php

namespace App\Data\Inbox;

use App\Data\Conversation\ListConversationItemData;
use App\Data\EnumOptionData;
use App\Data\Tag\TagOptionData;
use App\Data\User\UserOptionData;
use App\Enums\InboxView;
use Spatie\LaravelData\Data;

/**
 * 收件箱页面 props。
 * 由对应 Show*Action 返回给 resources/js/pages/Inbox.vue 及 pages/inbox/*，用于渲染首屏数据、筛选项和页面状态。
 */
class ShowInboxPagePropsData extends Data
{
    /**
     * 承载收件箱首屏列表、选中会话、筛选状态和统计数据。
     */
    public function __construct(
        public InboxView $current_view,
        public ?string $current_channel_id,
        public ?string $current_assignee,
        public ?string $current_search,
        public bool $current_important_only,
        public ?string $current_conversation_id,
        /** @var EnabledWebChannelData[] */
        public array $enabled_web_channels,
        /** @var UserOptionData[] */
        public array $teammates,
        /** @var ListConversationItemData[] */
        public array $conversation_list,
        public ?InboxSelectionData $selection,
        /** @var TagOptionData[] */
        public array $available_contact_tags,
        /** @var TagOptionData[] 会话维度标签选项，供摘要块人工打标签选择器使用 */
        public array $available_conversation_tags,
        /** @var EnumOptionData[] */
        public array $reception_language_options,
        /** @var EnumOptionData[] */
        public array $reply_assistant_mode_options,
        /** @var EnumOptionData[] */
        public array $reply_polish_tone_options,
        public InboxTabCountsData $tab_counts,
    ) {}
}
