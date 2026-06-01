<?php

namespace App\Services\Conversation;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\GoBridge\GoBridgeClient;
use RuntimeException;

/**
 * PHP 到 Go AI 运行时的会话主题生成桥接。
 */
class GoConversationSubjectBridge
{
    private const PATH = 'ai/conversation-subject/generate';

    private const TIMEOUT_SECONDS = 35;

    /**
     * 注入通用 Go 内部桥接客户端。
     */
    public function __construct(
        private readonly GoBridgeClient $client,
    ) {}

    /**
     * 根据会话消息生成短主题。
     *
     * @param  list<string>  $messages
     */
    public function generate(AiProvider $provider, AiModel $model, array $messages): string
    {
        $response = $this->client->postJson(self::PATH, [
            'provider' => $this->providerPayload($provider),
            'model' => $this->modelPayload($model),
            'messages' => array_values($messages),
        ], self::TIMEOUT_SECONDS);

        $body = $response->body;
        if (! $response->successful || ($body['success'] ?? false) !== true) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Conversation subject generation failed.';

            throw new RuntimeException($message);
        }

        $subject = is_string($body['subject'] ?? null) ? trim($body['subject']) : '';
        if ($subject === '') {
            throw new RuntimeException('Conversation subject generation returned an empty subject.');
        }

        return $subject;
    }

    /**
     * 构造供应商 payload。
     *
     * @return array<string, mixed>
     */
    private function providerPayload(AiProvider $provider): array
    {
        return [
            'slug' => (string) $provider->slug,
            'name' => (string) $provider->name,
            'brand' => (string) $provider->brand,
            'protocol' => $provider->protocol->value,
            'credentials' => $this->normalizeCredentials($provider->credentials ?? []),
            'credential_fields' => $provider->credential_fields,
            'models' => [],
        ];
    }

    /**
     * 构造模型 payload。
     *
     * @return array<string, mixed>
     */
    private function modelPayload(AiModel $model): array
    {
        return [
            'model_id' => (string) $model->model_id,
            'name' => (string) $model->name,
            'type' => (string) $model->type,
            'is_active' => (bool) $model->is_active,
        ];
    }

    /**
     * 清理凭据，只保留标量字符串。
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

            $normalized[$key] = trim((string) $value);
        }

        return $normalized;
    }
}
