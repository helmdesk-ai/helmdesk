<?php

namespace App\Enums;

/**
 * 会话事件展示密度，控制活动记录默认展开方式。
 */
enum ConversationEventDisplayMode: string
{
    case Inline = 'inline';
    case Collapsed = 'collapsed';
}
