<?php

namespace App\Actions\Mcp;

use App\Data\Mcp\FormCreateMcpServerData;
use App\Enums\UserPermission;
use App\Models\McpServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 在系统下创建新的 MCP 服务记录。
 */
class CreateMcpServerAction
{
    use AsAction;

    /**
     * 注入工具同步入队动作。
     */
    public function __construct(
        private readonly QueueMcpServerToolSyncAction $queueToolSync,
    ) {}

    /**
     * 创建新的 MCP 服务，并派发异步工具同步任务。
     */
    public function handle(FormCreateMcpServerData $data): McpServer
    {
        $maxSort = McpServer::query()->max('sort_order') ?? 0;

        /** @var McpServer $server */
        $server = McpServer::query()->create([
            'slug' => $this->generateSlug($data->name),
            'name' => $data->name,
            'transport' => $data->transport,
            'endpoint_url' => $data->endpoint_url,
            'credentials' => $this->buildCredentials($data->auth_header_name, $data->auth_header_value),
            'headers' => $this->normalizeHeaders($data->headers),
            'timeout_seconds' => $data->timeout_seconds ?? 30,
            'sort_order' => $maxSort + 1,
        ]);

        $this->queueToolSync->handle($server);

        return $server->refresh();
    }

    /**
     * 路由入口：校验权限后创建并 302 到列表页。
     */
    public function asController(Request $request): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $data = FormCreateMcpServerData::from($request);
        $this->handle($data);

        return redirect()->route('admin.manage.mcp.servers.index');
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
     * 归一化自定义请求头：丢弃非字符串值，键大小写保留。
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

    /**
     * 在 system 范围内生成唯一 slug。
     */
    private function generateSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'mcp';
        }

        do {
            $candidate = $base.'-'.Str::lower(Str::random(6));
            $exists = McpServer::query()->where('slug', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }
}
