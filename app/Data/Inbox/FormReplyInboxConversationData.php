<?php

namespace App\Data\Inbox;

use App\Actions\Reception\AppendTeammateMessageAction;
use Spatie\LaravelData\Data;

/**
 * 收件箱回复表单数据，用于校验并发送客服消息。
 */
class FormReplyInboxConversationData extends Data
{
    /**
     * 承载收件箱回复文本、可选附件 ID、客户端幂等键以及引用回复目标。
     */
    public function __construct(
        public ?string $content = null,
        /** @var list<string> */
        public array $attachment_ids = [],
        public ?string $client_msg_id = null,
        public ?string $quoted_message_id = null,
        public ?string $visitor_content = null,
        public ?string $visitor_locale = null,
        public ?string $source_locale = null,
    ) {}

    /**
     * 返回收件箱回复表单的验证规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:'.AppendTeammateMessageAction::MAX_CONTENT_LENGTH],
            'attachment_ids' => ['nullable', 'array', 'max:'.AppendTeammateMessageAction::MAX_ATTACHMENT_COUNT],
            'attachment_ids.*' => ['string', 'distinct'],
            'client_msg_id' => ['nullable', 'string', 'max:64'],
            'quoted_message_id' => ['nullable', 'string', 'max:64'],
            'visitor_content' => ['nullable', 'string', 'max:'.AppendTeammateMessageAction::MAX_CONTENT_LENGTH],
            'visitor_locale' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
            'source_locale' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
        ];
    }
}
