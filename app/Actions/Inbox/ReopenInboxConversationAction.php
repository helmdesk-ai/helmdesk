<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\ReopenConversationAction;
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
 * 从收件箱重新打开已关闭会话并分配给当前用户。
 */
class ReopenInboxConversationAction
{
    use AsAction;

    public function __construct(
        private readonly ReopenConversationAction $reopenConversationAction,
    ) {}

    public function handle(SystemContext $systemContext, User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        return $this->reopenConversationAction->handle($conversation, $user);
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
