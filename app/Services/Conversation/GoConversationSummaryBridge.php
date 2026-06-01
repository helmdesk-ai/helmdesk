<?php

namespace App\Services\Conversation;

use App\Data\Contact\GeneratedContactAiSummaryData;
use App\Data\Conversation\GeneratedConversationSummaryData;
use App\Data\Conversation\GeneratedConversationTagData;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\GoBridge\GoBridgeClient;
use RuntimeException;

/**
 * PHP 到 Go AI 运行时的会话与联系人摘要生成桥接。
 */
class GoConversationSummaryBridge
{
    private const CONVERSATION_PATH = 'ai/conversation-summary/generate';

    private const CONTACT_PATH = 'ai/contact-summary/generate';

    private const CONVERSATION_TAGS_PATH = 'ai/conversation-tags/generate';

    private const TIMEOUT_SECONDS = 60;

    /**
     * 注入通用 Go 内部桥接客户端。
     */
    public function __construct(
        private readonly GoBridgeClient $client,
    ) {}

    /**
     * 生成单次会话摘要。
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function generateConversation(
        AiProvider $provider,
        AiModel $model,
        string $locale,
        array $messages,
        ?string $existingSummary = null,
    ): GeneratedConversationSummaryData {
        $response = $this->client->postJson(self::CONVERSATION_PATH, [
            'provider' => $this->providerPayload($provider),
            'model' => $this->modelPayload($model),
            'locale' => $locale,
            'messages' => array_values($messages),
            'existing_summary' => filled($existingSummary) ? $existingSummary : null,
        ], self::TIMEOUT_SECONDS);

        $body = $response->body;
        if (! $response->successful || ($body['success'] ?? false) !== true) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Conversation summary generation failed.';

            throw new RuntimeException($message);
        }

        $summary = GeneratedConversationSummaryData::fromPayload($body);
        if ($summary->summary === '') {
            throw new RuntimeException('Conversation summary generation returned an empty summary.');
        }

        return $summary;
    }

    /**
     * 生成联系人 AI 摘要。
     *
     * @param  list<array<string, mixed>>  $conversationDigests
     * @param  array<string, mixed>|null  $existingSummary
     */
    public function generateContact(
        AiProvider $provider,
        AiModel $model,
        string $locale,
        array $conversationDigests,
        ?array $existingSummary = null,
    ): GeneratedContactAiSummaryData {
        $response = $this->client->postJson(self::CONTACT_PATH, [
            'provider' => $this->providerPayload($provider),
            'model' => $this->modelPayload($model),
            'locale' => $locale,
            'conversation_digests' => array_values($conversationDigests),
            'existing_summary' => $existingSummary,
        ], self::TIMEOUT_SECONDS);

        $body = $response->body;
        if (! $response->successful || ($body['success'] ?? false) !== true) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Contact summary generation failed.';

            throw new RuntimeException($message);
        }

        $summary = GeneratedContactAiSummaryData::fromPayload($body);
        if ($summary->profile_summary === '' && $summary->open_issues === [] && $summary->preferences === [] && $summary->recent_topics === []) {
            throw new RuntimeException('Contact summary generation returned an empty summary.');
        }

        return $summary;
    }

    /**
     * 从受控词表里为单次会话选出适用标签。
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array{tag_id: string, name: string, description: ?string, group: ?string}>  $candidates
     * @return list<GeneratedConversationTagData>
     */
    public function generateConversationTags(
        AiProvider $provider,
        AiModel $model,
        string $locale,
        array $candidates,
        ?string $summary = null,
        array $messages = [],
    ): array {
        $response = $this->client->postJson(self::CONVERSATION_TAGS_PATH, [
            'provider' => $this->providerPayload($provider),
            'model' => $this->modelPayload($model),
            'locale' => $locale,
            'summary' => filled($summary) ? $summary : null,
            'messages' => array_values($messages),
            'candidates' => array_values($candidates),
        ], self::TIMEOUT_SECONDS);

        $body = $response->body;
        if (! $response->successful || ($body['success'] ?? false) !== true) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Conversation tag generation failed.';

            throw new RuntimeException($message);
        }

        $tags = is_array($body['tags'] ?? null) ? $body['tags'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $tag): ?GeneratedConversationTagData => is_array($tag)
                ? GeneratedConversationTagData::fromPayload($tag)
                : null,
            $tags,
        )));
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
