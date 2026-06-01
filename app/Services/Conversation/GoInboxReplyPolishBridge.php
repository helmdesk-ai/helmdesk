<?php

namespace App\Services\Conversation;

use App\Data\Inbox\InboxReplyPolishContextData;
use App\Enums\ReplyAssistantMode;
use App\Enums\ReplyPolishTone;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\GoBridge\GoBridgeClient;
use RuntimeException;

/**
 * PHP 到 Go AI 运行时的收件箱 AI 回复助手桥接。
 */
class GoInboxReplyPolishBridge
{
    private const PATH = 'ai/reply-polish/generate';

    private const TIMEOUT_SECONDS = 35;

    /**
     * 注入通用 Go 内部桥接客户端。
     */
    public function __construct(
        private readonly GoBridgeClient $client,
    ) {}

    /**
     * 调用 Go 运行时生成多条候选客服回复。
     *
     * @return list<string>
     */
    public function generate(
        AiProvider $provider,
        AiModel $model,
        ReplyAssistantMode $mode,
        string $content,
        ReplyPolishTone $tone,
        InboxReplyPolishContextData $context,
    ): array {
        $response = $this->client->postJson(self::PATH, [
            'provider' => $this->providerPayload($provider),
            'model' => $this->modelPayload($model),
            'mode' => $mode->value,
            'content' => $content,
            'tone' => $tone->value,
            'context' => $context->toArray(),
        ], self::TIMEOUT_SECONDS);

        $body = $response->body;
        if (! $response->successful || ($body['success'] ?? false) !== true) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Inbox reply polish failed.';

            throw new RuntimeException($message);
        }

        $candidates = $this->normalizeCandidates($body['candidates'] ?? null);
        if ($candidates === []) {
            throw new RuntimeException('Inbox reply assistant returned empty candidates.');
        }

        return $candidates;
    }

    /**
     * 规范 Go 运行时返回的候选回复列表。
     *
     * @return list<string>
     */
    private function normalizeCandidates(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $candidates = [];
        foreach ($raw as $candidate) {
            $content = is_string($candidate) ? trim($candidate) : '';
            if ($content === '') {
                continue;
            }

            $candidates[] = $content;
        }

        return array_values($candidates);
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
     * 清理凭据，只保留非空标量字符串。
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
}
