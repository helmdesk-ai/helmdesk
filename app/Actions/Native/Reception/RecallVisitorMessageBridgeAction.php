<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\RecallVisitorMessageAction;
use App\Data\Reception\NativeReceptionStateData;
use App\Models\Conversation;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Native bridge 入口：撤回访客消息。
 */
class RecallVisitorMessageBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责撤回的访客侧 Action。
     */
    public function __construct(
        private readonly RecallVisitorMessageAction $recallVisitorMessageAction,
    ) {}

    /**
     * 将 Go 传入的小类型参数转换后撤回指定消息。
     */
    public function handle(
        string $channelCode,
        ?string $sessionToken,
        string $messageId,
        ?string $userToken = null,
    ): NativeReceptionStateData {
        $state = $this->recallVisitorMessageAction->handle(
            channelCode: $channelCode,
            sessionToken: $sessionToken,
            messageId: $messageId,
            userToken: $userToken,
        );

        $conversation = Conversation::query()->findOrFail($state->conversation_id);

        return NativeReceptionStateData::fromReceptionState($state, $conversation);
    }
}
