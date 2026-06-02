<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\ReleaseConversationToAiAction;
use App\Data\WorkspaceUserContextData;
use App\Enums\ConversationInboxStatus;
use App\Enums\InboxView;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 从收件箱把当前客服负责的会话交回 AI。
 */
class ReleaseInboxConversationToAiAction
{
    use AsAction;

    /**
     * 注入接待侧释放会话动作。
     */
    public function __construct(
        private readonly ReleaseConversationToAiAction $releaseConversationToAiAction,
    ) {}

    public function handle(Workspace $workspace, User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        return $this->releaseConversationToAiAction->handle($conversation, $user);
    }

    /**
     * 从收件箱入口释放当前会话给 AI 或待接待队列。
     */
    public function asController(Request $request, string $conversationId): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $conversation = $this->handle(
            workspace: $ctx->workspace(),
            user: User::query()->findOrFail($ctx->user_id),
            conversationId: $conversationId,
        );

        return redirect()->route('workspace.inbox.show', [
            'view' => $conversation->inbox_status === ConversationInboxStatus::TeammatePending
                ? InboxView::Pending
                : InboxView::Ai,
            'conversation_id' => $conversation->id,
        ]);
    }
}
