<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\CloseConversationAction;
use App\Data\WorkspaceUserContextData;
use App\Enums\InboxView;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 从收件箱关闭会话。
 */
class CloseInboxConversationAction
{
    use AsAction;

    public function __construct(
        private readonly CloseConversationAction $closeConversationAction,
    ) {}

    public function handle(Workspace $workspace, User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        if ($conversation->assigned_user_id !== null && (string) $conversation->assigned_user_id !== (string) $user->id) {
            throw new BusinessException(__('conversation.errors.close_not_allowed_for_assignee'));
        }

        return $this->closeConversationAction->handle($conversation, $user);
    }

    public function asController(Request $request, string $conversationId): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $conversation = $this->handle(
            workspace: $ctx->workspace(),
            user: User::query()->findOrFail($ctx->user_id),
            conversationId: $conversationId,
        );

        return redirect()->route('workspace.inbox.show', [
            'view' => InboxView::Closed,
            'conversation_id' => $conversation->id,
        ]);
    }
}
