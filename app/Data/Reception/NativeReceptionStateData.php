<?php

namespace App\Data\Reception;

use App\Models\Conversation;

/**
 * Native bridge 内部接待状态。
 * 在公开接待状态基础上附带 Go 运行时决策所需的内部收件箱状态。
 */
class NativeReceptionStateData extends ReceptionStateData
{
    /**
     * 创建 Native bridge 接待状态。
     *
     * @param  ReceptionMessageData[]  $messages
     */
    public function __construct(
        string $session_token,
        string $conversation_id,
        string $status,
        string $assistant_name,
        ?string $assistant_avatar_url,
        array $messages,
        public string $inbox_status,
    ) {
        parent::__construct(
            session_token: $session_token,
            conversation_id: $conversation_id,
            status: $status,
            assistant_name: $assistant_name,
            assistant_avatar_url: $assistant_avatar_url,
            messages: $messages,
        );
    }

    public static function fromReceptionState(ReceptionStateData $state, Conversation $conversation): self
    {
        return new self(
            session_token: $state->session_token,
            conversation_id: $state->conversation_id,
            status: $state->status,
            assistant_name: $state->assistant_name,
            assistant_avatar_url: $state->assistant_avatar_url,
            messages: $state->messages,
            inbox_status: $conversation->inbox_status->value,
        );
    }
}
