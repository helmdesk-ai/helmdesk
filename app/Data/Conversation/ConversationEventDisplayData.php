<?php

namespace App\Data\Conversation;

use App\Enums\ConversationEventDisplayMode;
use App\Enums\ConversationEventSemanticType;
use App\Enums\ConversationEventTone;
use Spatie\LaravelData\Data;

/**
 * 会话事件展示数据。
 * 由后端把原始事件 payload 转换为客服时间线可直接渲染的活动记录。
 */
class ConversationEventDisplayData extends Data
{
    /**
     * 创建会话事件展示数据。
     *
     * @param  ConversationEventFactData[]  $facts
     */
    public function __construct(
        public string $summary,
        public ?string $detail,
        public ConversationEventSemanticType $semantic_type,
        public ConversationEventTone $tone,
        public ConversationEventDisplayMode $display_mode,
        public array $facts = [],
    ) {}
}
