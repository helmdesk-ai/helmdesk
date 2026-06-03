<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\RecallTeammateMessageAction;
use App\Data\SystemUserContextData;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 从收件箱撤回当前用户发送或 AI 兜底的消息。
 */
class RecallInboxConversationMessageAction
{
    use AsAction;

    /**
     * 注入客服侧撤回 Action。
     */
    public function __construct(
        private readonly RecallTeammateMessageAction $recallTeammateMessageAction,
    ) {}

    /**
     * 校验会话归属并触发撤回。
     */
    public function handle(User $user, string $conversationId, string $messageId): void
    {
        $conversation = Conversation::query()
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $this->recallTeammateMessageAction->handle(
            conversation: $conversation,
            actor: $user,
            messageId: $messageId,
        );
    }

    /**
     * 接收撤回请求并回到收件箱页面。
     */
    public function asController(Request $request, string $conversationId, string $messageId): RedirectResponse
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $user = User::query()->findOrFail($ctx->user_id);

        $this->handle(
            user: $user,
            conversationId: $conversationId,
            messageId: $messageId,
        );

        return redirect()->route('admin.inbox.show', [
            'conversation_id' => $conversationId,
        ]);
    }
}
