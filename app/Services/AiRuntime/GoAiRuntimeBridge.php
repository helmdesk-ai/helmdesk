<?php

namespace App\Services\AiRuntime;

use App\Enums\AiProviderProtocol;
use App\Models\AiProvider;
use App\Services\GoBridge\Exceptions\GoBridgeInvalidResponseException;
use App\Services\GoBridge\Exceptions\GoBridgeNotConfiguredException;
use App\Services\GoBridge\Exceptions\GoBridgeUnavailableException;
use App\Services\GoBridge\GoBridgeClient;
use App\Services\GoBridge\GoBridgeResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * AI 运行时调用 Go 桥接的业务适配器。
 */
class GoAiRuntimeBridge
{
    /**
     * Go 侧 AI Provider 相关路由的公共前缀。
     */
    private const AI_PROVIDERS_PATH_PREFIX = 'ai/providers/';

    /**
     * lang/{locale}/ai.php 中 runtime 子树的前缀。
     */
    private const LANG_PREFIX = 'ai.runtime.';

    /**
     * 注入通用 Go 桥接客户端。
     */
    public function __construct(
        private GoBridgeClient $client,
    ) {}

    /**
     * 保存供应商前校验凭据配置。
     *
     * @param  array<string, mixed>  $credentials
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    public function validateProviderConfiguration(AiProvider $provider, array $credentials): array
    {
        return $this->send('validate', [
            'mode' => 'provider-save',
            'provider' => $this->providerPayload($provider, $credentials),
        ]);
    }

    /**
     * 保存模型前校验候选模型配置。
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $candidateModel
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    public function validateModelConfiguration(AiProvider $provider, array $credentials, array $candidateModel): array
    {
        return $this->send('validate', [
            'mode' => 'model-save',
            'provider' => $this->providerPayload($provider, $credentials, $candidateModel),
            'candidate_model' => $this->normalizeModelPayload($candidateModel),
        ]);
    }

    /**
     * 手动检查供应商连接是否可用。
     *
     * @param  array<string, mixed>  $credentials
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    public function checkProviderConnection(AiProvider $provider, array $credentials): array
    {
        return $this->send('check', [
            'mode' => 'connection-check',
            'provider' => $this->providerPayload($provider, $credentials),
        ]);
    }

    /**
     * 发送 AI 运行时桥接请求并归一化异常。
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    private function send(string $operation, array $payload): array
    {
        try {
            $response = $this->client->postJson(self::AI_PROVIDERS_PATH_PREFIX.$operation, $payload);
        } catch (GoBridgeNotConfiguredException) {
            Log::warning('Go AI runtime bridge base URL is not configured.', [
                'operation' => $operation,
            ]);

            return $this->buildResult(
                success: false,
                supported: false,
                code: 'bridge.not_configured',
                params: [],
                remoteMessage: 'Go AI runtime bridge base URL is not configured.',
                warnings: [],
            );
        } catch (GoBridgeUnavailableException $exception) {
            Log::warning('Go AI runtime bridge unavailable.', [
                'operation' => $operation,
                'exception' => $exception->getMessage(),
            ]);

            return $this->buildResult(
                success: false,
                supported: false,
                code: 'bridge.unavailable',
                params: ['error' => $exception->getMessage()],
                remoteMessage: 'Go AI runtime bridge is unavailable: '.$exception->getMessage(),
                warnings: [],
            );
        } catch (GoBridgeInvalidResponseException) {
            return $this->buildResult(
                success: false,
                supported: false,
                code: 'bridge.invalid_response',
                params: [],
                remoteMessage: 'Go AI runtime bridge returned an invalid response.',
                warnings: [],
            );
        }

        return $this->parseResponse($response);
    }

    /**
     * 把供应商和凭据整理成 Go 侧 payload。
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $candidateModel
     * @return array<string, mixed>
     */
    private function providerPayload(AiProvider $provider, array $credentials, ?array $candidateModel = null): array
    {
        return [
            'slug' => (string) $provider->slug,
            'name' => (string) $provider->name,
            'brand' => (string) $provider->brand,
            'protocol' => $provider->protocol instanceof AiProviderProtocol ? $provider->protocol->value : (string) $provider->protocol,
            'credentials' => $this->normalizeCredentials($credentials),
            'credential_fields' => $provider->credential_fields,
            'models' => $this->modelsPayload($provider, $candidateModel),
        ];
    }

    /**
     * 清理凭据里的非标量值和首尾空白。
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

    /**
     * 整理供应商当前模型列表。
     *
     * @param  array<string, mixed>|null  $candidateModel
     * @return array<int, array<string, mixed>>
     */
    private function modelsPayload(AiProvider $provider, ?array $candidateModel = null): array
    {
        /** @var Collection<int, array<string, mixed>> $models */
        $models = $provider->models()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($model) => [
                'model_id' => (string) $model->model_id,
                'name' => (string) $model->name,
                'type' => (string) $model->type,
                'is_active' => (bool) $model->is_active,
            ]);

        if ($candidateModel !== null) {
            $normalizedCandidate = $this->normalizeModelPayload($candidateModel);

            $models = $models
                ->reject(fn (array $model): bool => $model['model_id'] === $normalizedCandidate['model_id']
                    && $model['type'] === $normalizedCandidate['type'])
                ->push($normalizedCandidate)
                ->values();
        }

        return $models->all();
    }

    /**
     * 整理单个模型 payload。
     *
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    private function normalizeModelPayload(array $model): array
    {
        return [
            'model_id' => (string) ($model['model_id'] ?? ''),
            'name' => (string) ($model['name'] ?? ''),
            'type' => (string) ($model['type'] ?? ''),
            'is_active' => (bool) ($model['is_active'] ?? false),
        ];
    }

    /**
     * 把 Go 响应解析成前端可用的校验结果。
     *
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    private function parseResponse(GoBridgeResponse $response): array
    {
        $payload = $response->body;

        $warnings = collect($payload['warnings'] ?? [])
            ->filter(fn ($warning): bool => is_string($warning) && $warning !== '')
            ->values()
            ->all();

        $code = is_string($payload['code'] ?? null) ? (string) $payload['code'] : '';
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $remoteMessage = (string) ($payload['message'] ?? '');

        return $this->buildResult(
            success: $response->successful && (bool) ($payload['success'] ?? false),
            supported: (bool) ($payload['supported'] ?? false),
            code: $code,
            params: $params,
            remoteMessage: $remoteMessage,
            warnings: $warnings,
        );
    }

    /**
     * 组装统一的运行时校验结果。
     *
     * @param  array<string, mixed>  $params
     * @param  array<int, string>  $warnings
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    private function buildResult(
        bool $success,
        bool $supported,
        string $code,
        array $params,
        string $remoteMessage,
        array $warnings,
    ): array {
        return [
            'success' => $success,
            'supported' => $supported,
            'code' => $code,
            'message' => $this->translateMessage($code, $params, $remoteMessage),
            'warnings' => $warnings,
        ];
    }

    /**
     * 把 Go 返回的 code 翻译成当前语言文案。
     *
     * @param  array<string, mixed>  $params
     */
    private function translateMessage(string $code, array $params, string $remoteMessage): string
    {
        if ($code !== '') {
            $key = self::LANG_PREFIX.$code;
            if (Lang::has($key)) {
                return (string) __($key, $this->stringifyParams($params));
            }
        }

        if ($remoteMessage !== '') {
            return $remoteMessage;
        }

        return (string) __(self::LANG_PREFIX.'bridge.request_failed');
    }

    /**
     * 把翻译参数转成可插值的字符串。
     *
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

            $normalized[$key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $normalized;
    }
}
