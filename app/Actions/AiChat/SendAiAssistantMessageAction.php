<?php

namespace App\Actions\AiChat;

use App\Data\SystemUserContextData;
use App\Enums\AiProviderProtocol;
use App\Models\AiModel;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use App\Services\GoBridge\Exceptions\GoBridgeException;
use App\Services\GoBridge\GoBridgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 把系统浮动框里输入的一条消息转发给 Go 侧流式处理器。
 */
class SendAiAssistantMessageAction
{
    use AsAction;

    /**
     * Go 侧 AI 对话桥接路径（相对内部桥接前缀）。
     */
    private const GO_CHAT_STREAM_PATH = 'ai/chat/stream';

    private const MAX_HISTORY_MESSAGES = 20;

    public function __construct(
        private GoBridgeClient $goBridge,
        private AiModelResolver $modelResolver,
        private CollectConfiguredMcpServersAction $collectMcpServers,
        private CollectActiveKnowledgeBasesAction $collectKnowledgeBases,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{topic: string, model: array{provider: string, name: string, model_id: string}}
     */
    public function handle(SystemContext $systemContext, string $prompt, array $history = [], ?string $modelId = null): array
    {
        $trimmed = trim($prompt);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'prompt' => __('ai.chat.prompt_required'),
            ]);
        }

        if (! is_string($modelId) || trim($modelId) === '') {
            throw ValidationException::withMessages([
                'model_id' => __('ai.chat.model_required'),
            ]);
        }

        $model = $this->resolveActiveModel($systemContext, trim($modelId));
        $provider = $model->provider;

        $protocol = $provider->protocol instanceof AiProviderProtocol
            ? $provider->protocol->value
            : (string) $provider->protocol;

        // 前端已做 history 校验，这里只做归一化。
        $messages = $this->buildMessagePayload($history, $trimmed);

        $topic = $this->makeTopic($systemContext);

        $payload = [
            'topic' => $topic,
            'provider' => [
                'slug' => (string) $provider->slug,
                'name' => (string) $provider->name,
                'protocol' => $protocol,
                'credentials' => $this->normalizeCredentials($provider->credentials ?? []),
                'credential_fields' => $provider->credential_fields,
                'models' => [],
            ],
            'model' => [
                'model_id' => (string) $model->model_id,
                'name' => (string) $model->name,
                'type' => (string) $model->type,
                'is_active' => (bool) $model->is_active,
            ],
            'messages' => $messages,
            'mcp_servers' => $this->collectMcpServers->handle(),
            'knowledge_bases' => $this->collectKnowledgeBases->handle($systemContext),
        ];

        try {
            $response = $this->goBridge->postJson(self::GO_CHAT_STREAM_PATH, $payload, timeoutSeconds: 10);
        } catch (GoBridgeException $exception) {
            Log::warning('AI chat bridge call failed.', [
                // 上游错误可能回吐凭据，写日志前先脱敏。
                'exception' => $this->sanitizeUpstreamError($exception->getMessage()),
            ]);

            throw new UnprocessableEntityHttpException(__('ai.chat.runtime_unavailable'));
        }

        if (! $response->successful) {
            $rawError = is_string($response->body['error'] ?? null)
                ? (string) $response->body['error']
                : null;

            $message = $rawError !== null
                ? $this->sanitizeUpstreamError($rawError)
                : __('ai.chat.runtime_unavailable');

            throw new UnprocessableEntityHttpException($message);
        }

        return [
            'topic' => $topic,
            'model' => [
                'provider' => (string) $provider->name,
                'name' => (string) $model->name,
                'model_id' => (string) $model->model_id,
            ],
        ];
    }

    /**
     * Laravel 路由入口：处理后台 AI 对话消息提交。
     */
    public function asController(Request $request): JsonResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();

        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'model_id' => ['required', 'string'],
            'history' => ['sometimes', 'array', 'max:'.self::MAX_HISTORY_MESSAGES],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant,system'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        $history = array_values(array_map(
            fn (array $entry): array => [
                'role' => (string) ($entry['role'] ?? 'user'),
                'content' => (string) ($entry['content'] ?? ''),
            ],
            $validated['history'] ?? [],
        ));

        $payload = $this->handle(
            $systemContext,
            (string) $validated['prompt'],
            $history,
            (string) $validated['model_id'],
        );

        return response()->json($payload, Response::HTTP_ACCEPTED);
    }

    /**
     * 选出当前系统应当使用的有效 LLM 模型。
     */
    private function resolveActiveModel(SystemContext $systemContext, string $modelId): AiModel
    {
        if (! $this->modelResolver->isValidActiveLlmModel($systemContext, $modelId)) {
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
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessagePayload(array $history, string $prompt): array
    {
        $messages = [];

        foreach (array_slice($history, -self::MAX_HISTORY_MESSAGES) as $entry) {
            $role = (string) ($entry['role'] ?? '');
            $content = trim((string) ($entry['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            if (! in_array($role, ['user', 'assistant', 'system'], true)) {
                throw ValidationException::withMessages([
                    'history' => __('validation.in', ['attribute' => 'history.role']),
                ]);
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        return $messages;
    }

    /**
     * 把凭据 key-value map 归一化为纯字符串 map：trim 每个值，跳过空字符串。
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    private function normalizeCredentials(array $credentials): array
    {
        $normalized = [];

        foreach ($credentials as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }
            $normalized[$key] = $stringValue;
        }

        return $normalized;
    }

    /**
     * 为当前对话生成不可预测的 Mercure topic。
     */
    private function makeTopic(SystemContext $systemContext): string
    {
        return sprintf('urn:helmdesk:ai-chat:%s:%s', $systemContext->id, (string) Str::ulid());
    }

    /**
     * 脱敏并裁短上游错误，避免把凭据写进日志或返回前端。
     * 这里先脱敏再截断，避免把命中的 token 截断后漏掉掩码。
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
