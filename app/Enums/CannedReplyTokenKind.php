<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 快捷回复模版变量的命名空间分类。
 * `Ai` 是 v2 留口；v1 渲染遇到时原样保留并记录 warning。
 */
enum CannedReplyTokenKind: string implements LabeledEnum
{
    case Contact = 'contact';
    case Conversation = 'conversation';
    case Teammate = 'teammate';
    case System = 'system';
    case Ai = 'ai';

    /**
     * 返回命名空间的多语言显示文案。
     */
    public function label(): string
    {
        return match ($this) {
            self::Contact => __('canned_reply.token_kinds.contact'),
            self::Conversation => __('canned_reply.token_kinds.conversation'),
            self::Teammate => __('canned_reply.token_kinds.teammate'),
            self::System => __('canned_reply.token_kinds.system'),
            self::Ai => __('canned_reply.token_kinds.ai'),
        };
    }
}
