<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\RequestHandoffAction;
use App\Data\Reception\HandoffDecisionData;
use App\Models\Conversation;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Native bridge 入口：AI 接待主动请求转人工。
 */
class RequestHandoffBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责转人工的业务 Action。
     */
    public function __construct(
        private readonly RequestHandoffAction $requestHandoffAction,
    ) {}

    /**
     * 按 conversation_id 查找会话并请求转人工，保持 actor 异步触发时的会话归属。
     * reason 默认 ai_requested，提示文本由渠道配置和人工状态决定。
     */
    public function handle(
        string $conversationId,
        ?string $reason = null,
        ?string $quotedMessageId = null,
        ?string $summary = null,
    ): HandoffDecisionData {
        $conversation = Conversation::query()->with('channel.receptionPlanVersion')->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        return $this->requestHandoffAction->handle(
            conversation: $conversation,
            reason: $reason !== null && trim($reason) !== '' ? trim($reason) : 'ai_requested',
            quotedMessageId: $quotedMessageId,
            summary: $summary,
        );
    }
}
