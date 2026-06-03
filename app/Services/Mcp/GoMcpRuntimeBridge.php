<?php

namespace App\Services\Mcp;

use App\Enums\McpTransport;
use App\Models\McpServer;
use App\Services\GoBridge\Exceptions\GoBridgeInvalidResponseException;
use App\Services\GoBridge\Exceptions\GoBridgeNotConfiguredException;
use App\Services\GoBridge\Exceptions\GoBridgeUnavailableException;
use App\Services\GoBridge\GoBridgeClient;
use App\Services\GoBridge\GoBridgeResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * MCP 运行时调用 Go 桥接的业务适配器。
 * 镜像 GoAiRuntimeBridge 形态：每次请求传完整 server config + credentials，Go 端无状态。
 */
class GoMcpRuntimeBridge
{
    /**
     * Go 侧 MCP 相关路由的公共前缀。
     */
    private const MCP_PATH_PREFIX = 'mcp/servers/';

    /**
     * lang/{locale}/mcp.php 中 runtime 子树的前缀。
     */
    private const LANG_PREFIX = 'mcp.runtime.';

    /**
     * 注入通用 Go 桥接客户端。
     */
    public function __construct(
        private GoBridgeClient $client,
    ) {}

    /**
     * 手动测试连接：用户在编辑面板点 "测试连接" 时触发。
     *
     * @param  array<string, string>  $credentials
     * @param  array<string, string>|null  $headers
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    public function checkServerConnection(McpServer $server, array $credentials, ?array $headers = null): array
    {
        return $this->send('check', $this->serverPayload($server, $credentials, $headers));
    }

    /**
     * 拉取远端工具列表，由调用方对比写回 mcp_tools 表。
     *
     * @param  array<string, string>  $credentials
     * @param  array<string, string>|null  $headers
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>, tools: array<int, array<string, mixed>>}
     */
    public function listServerTools(McpServer $server, array $credentials, ?array $headers = null): array
    {
        $base = $this->send('list-tools', $this->serverPayload($server, $credentials, $headers));

        return $base + ['tools' => $this->lastTools];
    }

    /**
     * 缓存上一次 list-tools 响应里的 tools 数组。parseResponse 写入；listServerTools 读取。
     *
     * @var array<int, array<string, mixed>>
     */
    private array $lastTools = [];

    /**
     * 组装 Go 侧 server payload；transport 序列化为稳定 string，
     * credentials 约定结构 {auth_header_name, auth_header_value}，Go 端直接读 map。
     *
     * @param  array<string, string>  $credentials
     * @param  array<string, string>|null  $headers
     * @return array<string, mixed>
     */
    private function serverPayload(McpServer $server, array $credentials, ?array $headers): array
    {
        return [
            'server' => [
                'id' => (string) $server->id,
                'slug' => (string) $server->slug,
                'name' => (string) $server->name,
                'transport' => $server->transport instanceof McpTransport ? $server->transport->value : (string) $server->transport,
                'endpoint_url' => (string) $server->endpoint_url,
                // 空 map 必须强转 stdClass，否则 json_encode 输出 `[]`，Go 的 map[string]string unmarshal 会报错。
                'credentials' => $this->asJsonObject($this->normalizeStringMap($credentials)),
                'headers' => $this->asJsonObject($this->normalizeStringMap($headers ?? [])),
                'timeout_seconds' => (int) $server->timeout_seconds,
            ],
        ];
    }

    /**
     * 保证空 string map 序列化为 JSON `{}` 而不是 `[]`，
     * 非空时维持关联数组让 json_encode 当成对象处理。
     *
     * @param  array<string, string>  $map
     */
    private function asJsonObject(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass : $map;
    }

    /**
     * 发送 MCP 运行时桥接请求并归一化异常 / 翻译稳定 code。
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    private function send(string $operation, array $payload): array
    {
        $this->lastTools = [];

        try {
            $response = $this->client->postJson(self::MCP_PATH_PREFIX.$operation, $payload);
        } catch (GoBridgeNotConfiguredException) {
            Log::warning('Go MCP runtime bridge base URL is not configured.', [
                'operation' => $operation,
            ]);

            return $this->buildResult(
                success: false,
                supported: false,
                code: 'bridge.not_configured',
                params: [],
                remoteMessage: 'Go MCP runtime bridge base URL is not configured.',
                warnings: [],
            );
        } catch (GoBridgeUnavailableException $exception) {
            Log::warning('Go MCP runtime bridge unavailable.', [
                'operation' => $operation,
                'exception' => $exception->getMessage(),
            ]);

            return $this->buildResult(
                success: false,
                supported: false,
                code: 'bridge.unavailable',
                params: ['error' => $exception->getMessage()],
                remoteMessage: 'Go MCP runtime bridge is unavailable: '.$exception->getMessage(),
                warnings: [],
            );
        } catch (GoBridgeInvalidResponseException) {
            return $this->buildResult(
                success: false,
                supported: false,
                code: 'bridge.invalid_response',
                params: [],
                remoteMessage: 'Go MCP runtime bridge returned an invalid response.',
                warnings: [],
            );
        }

        return $this->parseResponse($response);
    }

    /**
     * 把 Go 响应解析成统一结果。list-tools 端点会额外返回 tools 数组，缓存到 lastTools。
     *
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    private function parseResponse(GoBridgeResponse $response): array
    {
        $payload = $response->body;

        if (isset($payload['tools']) && is_array($payload['tools'])) {
            $this->lastTools = $this->normalizeTools($payload['tools']);
        }

        $warnings = collect($payload['warnings'] ?? [])
            ->filter(fn ($warning): bool => is_string($warning) && $warning !== '')
            ->values()
            ->all();

        $code = is_string($payload['code'] ?? null) ? (string) $payload['code'] : '';
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $remoteMessage = (string) ($payload['message'] ?? '');

        return $this->buildResult(
            success: $response->successful && (bool) ($payload['success'] ?? false),
            supported: (bool) ($payload['supported'] ?? true),
            code: $code,
            params: $params,
            remoteMessage: $remoteMessage,
            warnings: $warnings,
        );
    }

    /**
     * 组装统一结果。
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
     * 翻译稳定 code；找不到 lang key 时回落到 Go 远端原始 message，最后兜底统一错误文案。
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

            $normalized[$key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return $normalized;
    }

    /**
     * 清理 string map 中的非标量与首尾空白。
     *
     * @param  array<string|int, mixed>  $values
     * @return array<string, string>
     */
    private function normalizeStringMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
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
     * 归一化 Go 返回的工具数组：保留 name / description / input_schema / annotations。
     *
     * @param  array<int|string, mixed>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTools(array $tools): array
    {
        $normalized = [];

        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $name = is_string($tool['name'] ?? null) ? (string) $tool['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'description' => is_string($tool['description'] ?? null) ? (string) $tool['description'] : null,
                'input_schema' => is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : null,
                'annotations' => is_array($tool['annotations'] ?? null) ? $tool['annotations'] : null,
            ];
        }

        return $normalized;
    }
}
