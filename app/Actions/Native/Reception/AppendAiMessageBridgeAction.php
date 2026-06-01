<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\AppendAiMessageAction;
use App\Data\Reception\ReceptionStateData;
use App\Models\Conversation;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Native bridge 入口：追加 AI 回复消息。
 */
class AppendAiMessageBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责追加 AI 回复的业务 Action。
     */
    public function __construct(
        private readonly AppendAiMessageAction $appendAiMessageAction,
    ) {}

    /**
     * 按 conversation_id 查找会话并追加 AI 回复，规避再次解析访客身份的副作用。
     *
     * quotedMessageId 用于让 AI 引用某条访客消息（与人工客服回复一致的引用 UX）；
     * 非法或已撤回的引用由底层 Action 静默丢弃，不影响 AI 回复本体。
     */
    public function handle(string $conversationId, string $content, ?string $quotedMessageId = null): ReceptionStateData
    {
        $conversation = Conversation::query()->with('channel.receptionPlanVersion')->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        return $this->appendAiMessageAction->handle($conversation, $content, $quotedMessageId);
    }
}
