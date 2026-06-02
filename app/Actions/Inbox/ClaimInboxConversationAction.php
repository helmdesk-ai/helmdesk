<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\ClaimConversationAction;
use App\Data\SystemUserContextData;
use App\Enums\InboxView;
use App\Models\Conversation;
use App\Models\SystemContext;
use App\Models\User;
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

    public function handle(SystemContext $systemContext, User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        return $this->claimConversationAction->handle($conversation, $user);
    }

    public function asController(Request $request, string $conversationId): RedirectResponse
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $conversation = $this->handle(
            systemContext: $ctx->systemContext(),
            user: User::query()->findOrFail($ctx->user_id),
            conversationId: $conversationId,
        );

        return redirect()->route('admin.inbox.show', [
            'view' => InboxView::Mine,
            'conversation_id' => $conversation->id,
        ]);
    }
}
