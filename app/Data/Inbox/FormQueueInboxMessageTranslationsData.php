<?php

namespace App\Data\Inbox;

use Spatie\LaravelData\Data;

/**
 * 收件箱自动翻译可见消息表单数据。
 */
class FormQueueInboxMessageTranslationsData extends Data
{
    /**
     * 承载前端当前可见且需要补翻的消息 ID。
     *
     * @param  list<string>  $message_ids
     */
    public function __construct(
        public array $message_ids,
    ) {}

    /**
     * 返回可见消息补翻请求的验证规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'message_ids' => ['required', 'array', 'min:1', 'max:20'],
            'message_ids.*' => ['required', 'string', 'distinct'],
        ];
    }
}
