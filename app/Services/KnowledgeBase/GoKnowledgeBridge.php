<?php

namespace App\Services\KnowledgeBase;

use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\GoBridge\Exceptions\GoBridgeInvalidResponseException;
use App\Services\GoBridge\Exceptions\GoBridgeNotConfiguredException;
use App\Services\GoBridge\Exceptions\GoBridgeUnavailableException;
use App\Services\GoBridge\GoBridgeClient;
use App\Services\GoBridge\GoBridgeResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * PHP → Go 知识库运行时桥接。负责嵌入与 RAPTOR 摘要两类调用。
 *
 * 通过通用 GoBridgeClient 调用 internal/app/integration/knowledge 下的内部运行时端点。
 */
class GoKnowledgeBridge
{
    /**
     * Go 侧 knowledge 路由组的统一前缀。
     */
    private const PATH_PREFIX = 'knowledge/';

    /**
     * 嵌入按 batch 调外部模型，单次给 90 秒；批量大时由调用方自行分批。
     */
    private const EMBED_TIMEOUT_SECONDS = 90;

    /**
     * RAPTOR 摘要单层最长 120 秒。
     */
    private const SUMMARIZE_TIMEOUT_SECONDS = 120;

    /**
     * 重排序单批次最长 30 秒；rerank 模型一般响应快，超时直接降级。
     */
    private const RERANK_TIMEOUT_SECONDS = 30;

    /**
     * 注入通用 Go 内部桥接客户端。
     */
    public function __construct(
        private readonly GoBridgeClient $client,
    ) {}

    /**
     * 按给定模型批量生成向量。
     *
     * @param  list<string>  $contents
     * @param  array<string, mixed>  $credentials
     * @return array{
     *     dimension: int,
     *     embeddings: list<list<float>>,
     * }
     */
    public function embedTexts(AiProvider $provider, AiModel $model, array $credentials, array $contents): array
    {
        if ($contents === []) {
            return ['dimension' => 0, 'embeddings' => []];
        }

        $response = $this->send('embed', [
            'provider' => $this->providerPayload($provider, $credentials),
            'model' => $this->modelPayload($model),
            'contents' => array_values($contents),
        ], self::EMBED_TIMEOUT_SECONDS);

        $body = $response->body;
        $dimension = (int) ($body['dimension'] ?? 0);
        $rawVectors = is_array($body['embeddings'] ?? null) ? array_values($body['embeddings']) : [];
        $vectors = [];
        foreach ($rawVectors as $row) {
            if (! is_array($row)) {
                continue;
            }
            $vectors[] = array_values(array_map(static fn ($v) => (float) $v, $row));
        }

        if ($dimension <= 0 || $vectors === []) {
            throw $this->failure($response, 'knowledge_runtime.embed.failed');
        }

        return ['dimension' => $dimension, 'embeddings' => $vectors];
    }

    /**
     * 对一组段落调用 LLM 生成单层摘要。Go 侧负责并发与节流。
     *
     * @param  array<string, mixed>  $credentials
     * @param  list<list<string>>  $batches  每个元素是一组要合并摘要的段文本
     * @return array{
     *     summaries: list<string>,
     * }
     */
    public function summarizeBatches(AiProvider $provider, AiModel $model, array $credentials, array $batches): array
    {
        if ($batches === []) {
            return ['summaries' => []];
        }

        $response = $this->send('summarize', [
            'provider' => $this->providerPayload($provider, $credentials),
            'model' => $this->modelPayload($model),
            'batches' => array_values($batches),
        ], self::SUMMARIZE_TIMEOUT_SECONDS);

        $body = $response->body;
        $summaries = is_array($body['summaries'] ?? null) ? array_values($body['summaries']) : [];

        return ['summaries' => array_map(static fn ($s) => (string) $s, $summaries)];
    }

    /**
     * 调外部 rerank 模型对一组候选文档按 query 进行重排。
     *
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $documents
     * @return array{
     *     results: list<array{index: int, score: float}>,
     * }
     */
    public function rerank(
        AiProvider $provider,
        AiModel $model,
        array $credentials,
        string $query,
        array $documents,
        int $topN = 0,
    ): array {
        if ($documents === [] || trim($query) === '') {
            return ['results' => []];
        }

        $payload = [
            'provider' => $this->providerPayload($provider, $credentials),
            'model' => $this->modelPayload($model),
            'query' => $query,
            'documents' => array_values($documents),
        ];
        if ($topN > 0) {
            $payload['top_n'] = $topN;
        }

        $response = $this->send('rerank', $payload, self::RERANK_TIMEOUT_SECONDS);

        $body = $response->body;
        $rawResults = is_array($body['results'] ?? null) ? array_values($body['results']) : [];
        $results = [];
        foreach ($rawResults as $row) {
            if (! is_array($row)) {
                continue;
            }
            $results[] = [
                'index' => (int) ($row['index'] ?? 0),
                'score' => (float) ($row['score'] ?? 0.0),
            ];
        }

        return ['results' => $results];
    }

    /**
     * 通用发送 + 错误归一化。
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(string $operation, array $payload, int $timeoutSeconds): GoBridgeResponse
    {
        try {
            $response = $this->client->postJson(self::PATH_PREFIX.$operation, $payload, $timeoutSeconds);
        } catch (GoBridgeNotConfiguredException $exception) {
            Log::warning('Knowledge runtime bridge base URL is not configured.', [
                'operation' => $operation,
            ]);
            throw new BusinessException($this->translate('knowledge_runtime.bridge.not_configured'), previous: $exception);
        } catch (GoBridgeUnavailableException $exception) {
            Log::warning('Knowledge runtime bridge unavailable.', [
                'operation' => $operation,
                'reason' => $exception->getMessage(),
            ]);
            throw new BusinessException($this->translate('knowledge_runtime.bridge.unavailable'), previous: $exception);
        } catch (GoBridgeInvalidResponseException $exception) {
            Log::warning('Knowledge runtime bridge returned invalid response.', [
                'operation' => $operation,
            ]);
            throw new BusinessException($this->translate('knowledge_runtime.bridge.invalid_response'), previous: $exception);
        }

        if (! $response->successful) {
            throw $this->failure($response, 'knowledge_runtime.bridge.request_failed');
        }

        $body = $response->body;
        if (isset($body['success']) && $body['success'] === false) {
            throw $this->failure($response, 'knowledge_runtime.bridge.request_failed');
        }

        return $response;
    }

    /**
     * 构造 provider 段 payload，复用 ai/providers 校验时的字段集合。
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function providerPayload(AiProvider $provider, array $credentials): array
    {
        return [
            'slug' => (string) $provider->slug,
            'name' => (string) $provider->name,
            'brand' => (string) $provider->brand,
            'protocol' => $provider->protocol->value,
            'credentials' => $credentials,
            'credential_fields' => is_array($provider->credential_fields) ? $provider->credential_fields : [],
        ];
    }

    /**
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
     * 把 Go 返回的失败响应转换成可对用户展示的 BusinessException。
     */
    private function failure(GoBridgeResponse $response, string $fallbackKey): BusinessException
    {
        $body = $response->body;
        $code = is_string($body['code'] ?? null) ? (string) $body['code'] : '';
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $remoteMessage = (string) ($body['message'] ?? '');

        if ($code !== '') {
            $key = 'knowledge_runtime.'.$code;
            if (Lang::has($key)) {
                return new BusinessException((string) __($key, $this->stringifyParams($params)));
            }
        }

        if ($remoteMessage !== '') {
            return new BusinessException($remoteMessage);
        }

        return new BusinessException($this->translate($fallbackKey));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function stringifyParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return $normalized;
    }

    private function translate(string $key): string
    {
        return (string) __($key);
    }
}
