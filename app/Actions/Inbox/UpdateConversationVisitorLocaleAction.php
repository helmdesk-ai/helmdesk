<?php

namespace App\Actions\Inbox;

use App\Data\Inbox\FormUpdateConversationVisitorLocaleData;
use App\Data\SystemUserContextData;
use App\Enums\ReceptionLanguage;
use App\Models\Conversation;
use App\Models\SystemContext;
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
    public function handle(SystemContext $systemContext, string $conversationId, ReceptionLanguage $visitorLocale): Conversation
    {
        $conversation = Conversation::query()
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
    public function asController(Request $request, string $conversationId): RedirectResponse
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $data = FormUpdateConversationVisitorLocaleData::from($request);

        $this->handle(
            systemContext: $ctx->systemContext(),
            conversationId: $conversationId,
            visitorLocale: $data->visitor_locale,
        );

        return redirect()->back();
    }
}
