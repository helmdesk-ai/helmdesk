<?php

namespace App\Services\Translation\Drivers;

use App\Models\TranslationProvider;
use App\Services\Translation\Exceptions\TranslationProviderException;
use App\Services\Translation\TranslatorContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * HTTP 翻译 driver 基类，集中处理凭据读取、耗时统计和上游异常包装。
 */
abstract class HttpTranslationDriver implements TranslatorContract
{
    /**
     * 注入实际承载凭据和 slug 的 TranslationProvider；driver 自己不持久化任何状态。
     */
    public function __construct(protected readonly TranslationProvider $provider) {}

    /**
     * 读取必填凭据字段，空值统一转成 TranslationProviderException。
     */
    protected function requiredCredential(string $field): string
    {
        $value = $this->credential($field);

        if ($value === '') {
            throw new TranslationProviderException(
                __('translation.driver_errors.missing_credential', [
                    'provider' => $this->provider->name,
                    'field' => $field,
                ]),
                providerSlug: $this->provider->slug,
            );
        }

        return $value;
    }

    /**
     * 读取可选凭据字段，缺失时返回空字符串。
     */
    protected function credential(string $field): string
    {
        $credentials = $this->provider->credentials;
        $value = is_array($credentials) ? ($credentials[$field] ?? '') : '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * 读取可选凭据字段，缺失时回退到默认值。
     */
    protected function credentialOrDefault(string $field, string $default): string
    {
        $value = $this->credential($field);

        return $value !== '' ? $value : $default;
    }

    /**
     * 返回统一 HTTP 超时时间。
     */
    protected function requestTimeout(): int
    {
        return (int) config('translation.request_timeout', 5);
    }

    /**
     * 当前毫秒时间戳，用于耗时统计。
     */
    protected function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * 计算从 startedAt 到当前的耗时毫秒数。
     */
    protected function latencyMs(int $startedAt): int
    {
        return $this->nowMs() - $startedAt;
    }

    /**
     * 包装网络连接失败。
     */
    protected function connectionFailed(ConnectionException $exception): TranslationProviderException
    {
        return new TranslationProviderException(
            __('translation.driver_errors.connection_failed', [
                'provider' => $this->provider->name,
                'message' => $exception->getMessage(),
            ]),
            providerSlug: $this->provider->slug,
            previous: $exception,
        );
    }

    /**
     * 包装上游 HTTP 错误响应。
     */
    protected function upstreamFailed(Response $response, ?string $message = null, ?\Throwable $previous = null): TranslationProviderException
    {
        return new TranslationProviderException(
            __('translation.driver_errors.upstream_error', [
                'provider' => $this->provider->name,
                'message' => $message ?? $response->body(),
            ]),
            statusCode: $response->status(),
            providerSlug: $this->provider->slug,
            previous: $previous,
        );
    }

    /**
     * 包装上游成功响应形状异常。
     */
    protected function missingTranslationsPayload(?int $statusCode = null): TranslationProviderException
    {
        return new TranslationProviderException(
            __('translation.driver_errors.missing_translations_payload', [
                'provider' => $this->provider->name,
            ]),
            statusCode: $statusCode,
            providerSlug: $this->provider->slug,
        );
    }

    /**
     * 把空 source 统一转成 auto。
     */
    protected function normalizeSourceLang(string $sourceLang): string
    {
        return trim($sourceLang) !== '' ? trim($sourceLang) : 'auto';
    }
}
