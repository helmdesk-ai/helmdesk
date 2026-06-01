<?php

namespace App\Actions\Inbox;

use App\Actions\Reception\TransferConversationToTeammateAction;
use App\Data\Inbox\FormTransferInboxConversationData;
use App\Data\WorkspaceUserContextData;
use App\Enums\InboxView;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 从收件箱将当前客服负责的会话转接给指定同事。
 */
class TransferInboxConversationAction
{
    use AsAction;

    /**
     * 注入接待侧会话转接动作。
     */
    public function __construct(
        private readonly TransferConversationToTeammateAction $transferConversationToTeammateAction,
    ) {}

    /**
     * 转接当前工作区内的指定会话。
     */
    public function handle(
        Workspace $workspace,
        User $user,
        string $conversationId,
        FormTransferInboxConversationData $data,
    ): Conversation {
        $conversation = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $target = $workspace->users()
            ->whereKey($data->target_user_id)
            ->first();

        if (! $target instanceof User) {
            throw ValidationException::withMessages([
                'target_user_id' => __('conversation.errors.transfer_target_not_found'),
            ]);
        }

        return $this->transferConversationToTeammateAction->handle($conversation, $user, $target);
    }

    /**
     * 接收转接请求并切到同事视图。
     */
    public function asController(Request $request, string $slug, string $conversationId): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $data = FormTransferInboxConversationData::from($request);
        $conversation = $this->handle(
            workspace: $ctx->workspace(),
            user: User::query()->findOrFail($ctx->user_id),
            conversationId: $conversationId,
            data: $data,
        );

        return redirect()->route('workspace.inbox.show', [
            'slug' => $slug,
            'view' => InboxView::Teammates,
            'conversation_id' => $conversation->id,
        ]);
    }
}
