<?php

namespace App\Data\Inbox;

use Spatie\LaravelData\Data;

/**
 * 收件箱会话摘要补翻表单数据。
 * 来自 B 端聊天视图的可见摘要自动翻译请求。
 */
class FormQueueInboxConversationSummaryTranslationsData extends Data
{
    /**
     * 创建摘要补翻请求数据。
     *
     * @param  list<string>  $conversation_ids
     */
    public function __construct(
        public array $conversation_ids,
    ) {}

    /**
     * 返回表单校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'conversation_ids' => ['required', 'array', 'max:20'],
            'conversation_ids.*' => ['required', 'string', 'ulid'],
        ];
    }
}
