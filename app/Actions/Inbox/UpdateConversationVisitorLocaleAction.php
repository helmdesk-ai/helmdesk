<?php

namespace App\Actions\Inbox;

use App\Data\Inbox\FormUpdateConversationVisitorLocaleData;
use App\Data\WorkspaceUserContextData;
use App\Enums\ReceptionLanguage;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 手动设置会话的访客语言。
 */
class UpdateConversationVisitorLocaleAction
{
    use AsAction;

    /**
     * 更新会话的 visitor_locale 字段。
     */
    public function handle(Workspace $workspace, string $conversationId, ReceptionLanguage $visitorLocale): Conversation
    {
        $conversation = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $conversation->update(['visitor_locale' => $visitorLocale->value]);

        return $conversation;
    }

    /**
     * 接收访客语言设置表单并返回收件箱页面。
     */
    public function asController(Request $request, string $slug, string $conversationId): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $data = FormUpdateConversationVisitorLocaleData::from($request);

        $this->handle(
            workspace: $ctx->workspace(),
            conversationId: $conversationId,
            visitorLocale: $data->visitor_locale,
        );

        return redirect()->back();
    }
}
