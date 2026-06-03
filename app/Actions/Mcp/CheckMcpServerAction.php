<?php

namespace App\Actions\Mcp;

use App\Data\Mcp\FormCreateMcpServerData;
use App\Data\Mcp\FormUpdateMcpServerData;
use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\McpServer;
use App\Models\SystemContext;
use App\Services\Mcp\GoMcpRuntimeBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 触发一次 MCP 服务连接测试，结果作为 toast 文案展示在前端。
 * 失败时将 Go 桥接归一化后的 message 回传给前端，由页面直接展示 toast。
 */
class CheckMcpServerAction
{
    use AsAction;

    /**
     * 注入 MCP 运行时桥。
     */
    public function __construct(
        private GoMcpRuntimeBridge $bridge,
    ) {}

    /**
     * 用当前表单配置触发连接测试，不落库。
     *
     * @return array{success: bool, code: string, message: string, supported: bool, warnings: array<int, string>}
     */
    public function handle(SystemContext $systemContext, ?string $slug, FormCreateMcpServerData|FormUpdateMcpServerData|null $data): array
    {
        $server = $slug === null
            ? null
            : $systemContext->mcpServers()->where('slug', $slug)->firstOrFail();
        $runtimeServer = $data === null ? $server : $this->serverForRuntimeCheck($server, $data);

        $result = $this->bridge->checkServerConnection(
            $runtimeServer,
            $runtimeServer->credentials ?? [],
            $runtimeServer->headers,
        );

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'endpoint_url' => $result['message'],
            ]);
        }

        return $result;
    }

    /**
     * 路由入口：需要系统设置编辑权限。
     */
    public function asController(Request $request, ?string $server = null): JsonResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        try {
            $data = match (true) {
                $server === null => FormCreateMcpServerData::from($request),
                $request->input() === [] => null,
                default => FormUpdateMcpServerData::from($request),
            };
            $this->handle($systemContext, $server, $data);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())
                ->flatten()
                ->unique()
                ->implode("\n");

            return response()->json([
                'success' => false,
                'message' => $message !== ''
                    ? $message
                    : __('mcp.runtime.check.failed', ['error' => __('mcp.runtime.bridge.request_failed')]),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => __('mcp.messages.check_succeeded'),
        ]);
    }

    /**
     * 基于当前表单配置构造临时 server，只用于运行时测试，不保存。
     */
    private function serverForRuntimeCheck(?McpServer $server, FormCreateMcpServerData|FormUpdateMcpServerData $data): McpServer
    {
        $runtimeServer = $server === null ? new McpServer : clone $server;
        $runtimeServer->id = $server?->id ?? '';
        $runtimeServer->slug = $server?->slug ?? '';
        $runtimeServer->name = $data->name;
        if ($data instanceof FormCreateMcpServerData) {
            $runtimeServer->transport = $data->transport;
        }
        $runtimeServer->endpoint_url = $data->endpoint_url;
        $runtimeServer->headers = $this->normalizeHeaders($data->headers);

        if ($data->timeout_seconds !== null) {
            $runtimeServer->timeout_seconds = $data->timeout_seconds;
        }

        $runtimeServer->credentials = $server === null
            ? $this->buildCredentials($data->auth_header_name, $data->auth_header_value)
            : $this->mergeCredentials($server, $data);

        return $runtimeServer;
    }

    /**
     * 与保存表单一致：clear_auth_credentials 清空；null 表示保留；非空字符串覆盖。
     *
     * @return array<string, string>|null
     */
    private function mergeCredentials(McpServer $server, FormUpdateMcpServerData $data): ?array
    {
        if ($data->clear_auth_credentials) {
            return null;
        }

        $current = $server->credentials ?? [];
        $currentName = is_string($current['auth_header_name'] ?? null) ? trim($current['auth_header_name']) : '';
        $currentValue = is_string($current['auth_header_value'] ?? null) ? trim($current['auth_header_value']) : '';

        $name = $data->auth_header_name === null ? $currentName : trim($data->auth_header_name);
        $value = $data->auth_header_value === null ? $currentValue : trim($data->auth_header_value);

        if ($name === '' || $value === '') {
            return null;
        }

        return [
            'auth_header_name' => $name,
            'auth_header_value' => $value,
        ];
    }

    /**
     * name / value 必须同时提供才视为已配置认证 header；否则视为无认证。
     *
     * @return array<string, string>|null
     */
    private function buildCredentials(?string $name, ?string $value): ?array
    {
        $name = $name !== null ? trim($name) : '';
        $value = $value !== null ? trim($value) : '';

        if ($name === '' || $value === '') {
            return null;
        }

        return [
            'auth_header_name' => $name,
            'auth_header_value' => $value,
        ];
    }

    /**
     * 归一化自定义请求头：丢弃非字符串值。
     *
     * @param  array<string, mixed>|null  $headers
     * @return array<string, string>|null
     */
    private function normalizeHeaders(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }
            $normalized[$key] = $stringValue;
        }

        return $normalized === [] ? null : $normalized;
    }
}
