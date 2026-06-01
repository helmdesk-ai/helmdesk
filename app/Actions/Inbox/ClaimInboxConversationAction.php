<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\ClaimConversationAction;
use App\Data\WorkspaceUserContextData;
use App\Enums\InboxView;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 从收件箱接起一条会话，并切回“我负责的”视图方便继续处理。
 */
class ClaimInboxConversationAction
{
    use AsAction;

    public function __construct(
        private readonly ClaimConversationAction $claimConversationAction,
    ) {}

    public function handle(Workspace $workspace, User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        return $this->claimConversationAction->handle($conversation, $user);
    }

    public function asController(Request $request, string $slug, string $conversationId): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $conversation = $this->handle(
            workspace: $ctx->workspace(),
            user: User::query()->findOrFail($ctx->user_id),
            conversationId: $conversationId,
        );

        return redirect()->route('workspace.inbox.show', [
            'slug' => $slug,
            'view' => InboxView::Mine,
            'conversation_id' => $conversation->id,
        ]);
    }
}
