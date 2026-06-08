<?php

namespace App\Actions\Inbox;

use App\Actions\Translation\TranslateConversationMessageAction;
use App\Data\Inbox\FormPreviewInboxReplyTranslationData;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversation\ConversationReplyPermission;
use App\Services\Localization\LocalePreference;
use App\Services\Translation\Exceptions\TranslationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 生成收件箱客服回复的访客可见内容预览。
 */
class PreviewInboxReplyTranslationAction
{
    use AsAction;

    /**
     * 注入消息翻译用例。
     */
    public function __construct(
        private readonly TranslateConversationMessageAction $translateAction,
        private readonly ConversationReplyPermission $replyPermission,
    ) {}

    /**
     * 翻译客服待发送文本，供发送前确认访客内容。
     *
     * @return array{visitor_content: ?string, visitor_locale: ?string, source_locale: ?string}
     */
    public function handle(User $user, string $conversationId, string $content): array
    {
        $conversation = Conversation::query()
            ->with(['channel'])
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        if (! $this->replyPermission->canReply($conversation, $user)) {
            return ['visitor_content' => null, 'visitor_locale' => null, 'source_locale' => null];
        }

        if ($conversation->channel === null) {
            throw new NotFoundHttpException;
        }

        $targetLang = $conversation->visitor_locale;
        if (LocalePreference::matches($user->locale, $targetLang)) {
            return [
                'visitor_content' => $content,
                'visitor_locale' => (string) $targetLang,
                'source_locale' => $user->locale,
            ];
        }

        try {
            $result = $this->translateAction->translateContentForTargetLang($content, (string) $targetLang);
        } catch (TranslationException $e) {
            Log::warning('客服回复访客内容预览失败', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return ['visitor_content' => null, 'visitor_locale' => (string) $targetLang, 'source_locale' => null];
        }

        return [
            'visitor_content' => LocalePreference::matches($result->source_lang, $targetLang) ? $content : $result->text,
            'visitor_locale' => $result->target_lang,
            'source_locale' => $result->source_lang,
        ];
    }

    /**
     * 接收访客内容预览请求并返回 JSON。
     */
    public function asController(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();
        $data = FormPreviewInboxReplyTranslationData::from($request);

        return response()->json($this->handle($user, $conversationId, $data->content));
    }
}
