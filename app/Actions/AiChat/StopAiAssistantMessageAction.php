<?php

namespace App\Actions\AiChat;

use App\Data\SystemUserContextData;
use App\Models\SystemContext;
use App\Services\GoBridge\Exceptions\GoBridgeException;
use App\Services\GoBridge\GoBridgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 停止指定 topic 上正在进行的 AI 流式回复。
 */
class StopAiAssistantMessageAction
{
    use AsAction;

    private const GO_CHAT_STOP_PATH = 'ai/chat/stop';

    public function __construct(
        private GoBridgeClient $goBridge,
    ) {}

    /**
     * @return array{stopped: bool}
     */
    public function handle(SystemContext $systemContext, string $topic): array
    {
        $topic = trim($topic);
        if ($topic === '' || ! str_starts_with($topic, $this->topicPrefix($systemContext))) {
            throw ValidationException::withMessages([
                'topic' => __('ai.chat.invalid_topic'),
            ]);
        }

        try {
            $response = $this->goBridge->postJson(self::GO_CHAT_STOP_PATH, [
                'topic' => $topic,
            ], timeoutSeconds: 5);
        } catch (GoBridgeException $exception) {
            // 不要把完整 topic 写日志：当前 Mercure 是匿名订阅，topic 字符串就是订阅密钥，
            // 任何能 grep 日志的人都能在窗口期内拉到这一轮对话的内容。
            Log::warning('AI chat stop bridge call failed.', [
                'topic_hash' => substr(hash('sha256', $topic), 0, 12),
                'exception' => $exception->getMessage(),
            ]);

            throw new UnprocessableEntityHttpException(__('ai.chat.runtime_unavailable'));
        }

        if (! $response->successful) {
            $message = is_string($response->body['error'] ?? null)
                ? (string) $response->body['error']
                : __('ai.chat.runtime_unavailable');

            throw new UnprocessableEntityHttpException($message);
        }

        if (! is_bool($response->body['stopped'] ?? null)) {
            throw new UnprocessableEntityHttpException(__('ai.chat.runtime_unavailable'));
        }

        return [
            'stopped' => $response->body['stopped'],
        ];
    }

    public function asController(Request $request): JsonResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();

        $validated = $request->validate([
            'topic' => ['required', 'string'],
        ]);

        return response()->json(
            $this->handle($systemContext, (string) $validated['topic']),
            Response::HTTP_OK,
        );
    }

    private function topicPrefix(SystemContext $systemContext): string
    {
        return sprintf('urn:helmdesk:ai-chat:%s:', $systemContext->id);
    }
}
