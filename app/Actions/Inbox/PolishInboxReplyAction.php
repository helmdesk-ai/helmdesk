<?php

namespace App\Actions\Inbox;

use App\Data\Inbox\FormPolishInboxReplyData;
use App\Data\Inbox\InboxReplyPolishCandidateData;
use App\Data\Inbox\InboxReplyPolishResultData;
use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use App\Services\Conversation\ConversationReplyPermission;
use App\Services\Conversation\GoInboxReplyPolishBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 使用 AI 为收件箱客服生成或改写候选回复。
 */
class PolishInboxReplyAction
{
    use AsAction;

    /**
     * 注入权限、模型解析、上下文构建和 Go 回复助手桥接。
     */
    public function __construct(
        private readonly ConversationReplyPermission $replyPermission,
        private readonly AiModelResolver $modelResolver,
        private readonly BuildInboxReplyPolishContextAction $buildContext,
        private readonly GoInboxReplyPolishBridge $bridge,
    ) {}

    /**
     * 校验会话和模型后，调用 AI 运行时返回候选回复。
     */
    public function handle(Workspace $workspace, User $user, string $conversationId, FormPolishInboxReplyData $data): InboxReplyPolishResultData
    {
        $conversation = Conversation::query()
            ->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $denialMessageKey = $this->replyPermission->denialMessageKey($conversation, $user);
        if ($denialMessageKey !== null) {
            throw new BusinessException(__($denialMessageKey));
        }

        $model = $this->resolveActiveModel($workspace, trim($data->model_id));
        $context = $this->buildContext->handle($conversation, $data->quoted_message_id, $user->locale);

        try {
            $candidateContents = $this->bridge->generate(
                provider: $model->provider,
                model: $model,
                mode: $data->mode,
                content: trim((string) $data->content),
                tone: $data->tone,
                context: $context,
            );
        } catch (RuntimeException $exception) {
            Log::warning('收件箱 AI 回复助手失败', [
                'conversation_id' => $conversation->id,
                'model_id' => $model->id,
                'error' => $this->sanitizeUpstreamError($exception->getMessage()),
            ]);

            throw new BusinessException(__('conversation.errors.reply_polish_failed'));
        }

        $candidates = [];
        foreach (array_values($candidateContents) as $index => $content) {
            $candidates[] = InboxReplyPolishCandidateData::fromContent($index, $content);
        }

        return new InboxReplyPolishResultData($candidates);
    }

    /**
     * 接收收件箱 AI 回复助手请求并返回 JSON。
     */
    public function asController(Request $request, string $conversationId): JsonResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $user = User::query()->findOrFail($ctx->user_id);
        $data = FormPolishInboxReplyData::from($request);

        return response()->json($this->handle(
            workspace: $ctx->workspace(),
            user: $user,
            conversationId: $conversationId,
            data: $data,
        )->toArray());
    }

    /**
     * 选出当前工作区中启用的 LLM 模型。
     */
    private function resolveActiveModel(Workspace $workspace, string $modelId): AiModel
    {
        if (! $this->modelResolver->isValidActiveLlmModel($workspace, $modelId)) {
            throw ValidationException::withMessages([
                'model_id' => __('ai.chat.selected_model_unavailable'),
            ]);
        }

        $model = AiModel::query()
            ->with('provider')
            ->whereHas('provider')
            ->find($modelId);

        if ($model === null || $model->provider === null) {
            throw ValidationException::withMessages([
                'model_id' => __('ai.chat.selected_model_unavailable'),
            ]);
        }

        return $model;
    }

    /**
     * 脱敏并裁短上游错误，避免凭据进入日志。
     */
    private function sanitizeUpstreamError(string $message): string
    {
        $patterns = [
            '/sk-[A-Za-z0-9_\-]{16,}/i' => '[redacted-key]',
            '/Bearer\s+[A-Za-z0-9._\-]+/i' => 'Bearer [redacted]',
            '/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/' => '[redacted-jwt]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }

        return mb_substr($message, 0, 200);
    }
}
