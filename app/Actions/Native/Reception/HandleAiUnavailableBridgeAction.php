<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\HandleAiUnavailableAction;
use App\Models\Conversation;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Native bridge 入口：所有接待模型均不可用时，发送兜底文案并将会话转为人工待接。
 */
class HandleAiUnavailableBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责 AI 不可用处理的业务 Action。
     */
    public function __construct(
        private readonly HandleAiUnavailableAction $handleAiUnavailableAction,
    ) {}

    /**
     * 按 conversation_id 查找会话并委托给业务 Action 处理。
     *
     * @return array{handled: bool}
     */
    public function handle(string $conversationId, string $notice): array
    {
        $conversation = Conversation::query()->with('channel')->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        return $this->handleAiUnavailableAction->handle($conversation, $notice);
    }
}
