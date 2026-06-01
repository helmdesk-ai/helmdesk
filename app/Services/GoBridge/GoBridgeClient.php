<?php

namespace App\Services\GoBridge;

use App\Services\GoBridge\Exceptions\GoBridgeInvalidResponseException;
use App\Services\GoBridge\Exceptions\GoBridgeNotConfiguredException;
use App\Services\GoBridge\Exceptions\GoBridgeUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * 通用的 Go 内部桥接客户端。
 */
final class GoBridgeClient
{
    /**
     * 内部桥接的公共路径前缀。Go 侧 RegisterInternalBridge 使用相同的前缀。
     */
    private const INTERNAL_PATH_PREFIX = '/_helmdesk/internal/';

    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * 注入 Laravel HTTP 客户端。
     */
    public function __construct(
        private HttpFactory $http,
    ) {}

    /**
     * 向 Go 内部桥接发送 JSON POST 请求。
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws GoBridgeNotConfiguredException base_url 未配置时抛出
     * @throws GoBridgeUnavailableException 连接失败、超时或拒绝连接时抛出
     * @throws GoBridgeInvalidResponseException 响应体不是可解析 JSON 时抛出
     */
    public function postJson(string $path, array $payload, ?int $timeoutSeconds = null): GoBridgeResponse
    {
        $url = $this->resolveUrl($path);
        $timeout = $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;

        try {
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->timeout($timeout)
                // 内部桥接走 127.0.0.1 loopback，强制绕开 HTTP_PROXY / HTTPS_PROXY 环境代理：
                // 当宿主 shell 配置了上游代理时（例如 socks5 转 http），Guzzle 默认会把这条 loopback
                // 请求也丢给代理，代理对 127.0.0.1 处理不了于是 RST，cURL 端表现为 error 52。
                ->withOptions(['proxy' => false])
                ->withHeaders([
                    'X-Helmdesk-Bridge-Token' => (string) config('services.go_runtime.bridge_token', ''),
                ])
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new GoBridgeUnavailableException($exception->getMessage(), previous: $exception);
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new GoBridgeInvalidResponseException($response->status());
        }

        return new GoBridgeResponse(
            status: $response->status(),
            successful: $response->successful(),
            body: $body,
        );
    }

    /**
     * 拼出 Go 内部桥接的完整 URL。
     */
    private function resolveUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('services.go_runtime.base_url', ''), '/');

        if ($baseUrl === '') {
            throw new GoBridgeNotConfiguredException;
        }

        return $baseUrl.self::INTERNAL_PATH_PREFIX.ltrim($path, '/');
    }
}
