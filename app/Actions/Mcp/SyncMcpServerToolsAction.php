<?php

namespace App\Actions\Mcp;

use App\Data\SystemUserContextData;
use App\Enums\McpSyncStatus;
use App\Enums\UserPermission;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Models\SystemContext;
use App\Services\Mcp\GoMcpRuntimeBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 拉取远端工具列表并写回 mcp_tools 缓存。
 *
 * 策略：
 *  - 新工具：写入基础描述与 schema；
 *  - 已有工具：更新描述 / schema / last_seen_at，已下线状态会被清除（remote 又出现）；
 *  - 远端不再返回的工具：置 removed_at，用于页面展示下线状态；
 *  - 同步成功 / 失败都会写 last_sync_status 与 last_sync_error，便于详情页提示。
 */
class SyncMcpServerToolsAction
{
    use AsAction;

    /**
     * 注入 MCP 运行时桥。
     */
    public function __construct(
        private GoMcpRuntimeBridge $bridge,
    ) {}

    /**
     * 触发一次同步。同步失败不抛异常，结果以 last_sync_status / error 回写。
     *
     * @return array{success: bool, code: string, message: string, total: int, added: int, removed: int, warnings: array<int, string>}
     */
    public function handle(SystemContext $systemContext, string $slug): array
    {
        $server = $systemContext->mcpServers()->where('slug', $slug)->firstOrFail();

        return $this->syncServer($server);
    }

    /**
     * 同步指定 MCP 服务的工具列表，并回写该服务的同步状态。
     *
     * @return array{success: bool, code: string, message: string, total: int, added: int, removed: int, warnings: array<int, string>}
     */
    public function syncServer(McpServer $server): array
    {
        $server->last_sync_status = McpSyncStatus::Syncing;
        $server->last_sync_error = null;
        $server->save();

        $result = $this->bridge->listServerTools(
            $server,
            $server->credentials ?? [],
            $server->headers,
        );

        if (! $result['success']) {
            $server->last_sync_status = McpSyncStatus::Failed;
            $server->last_sync_error = $result['message'];
            $server->last_synced_at = now();
            $server->save();

            Log::info('MCP tool sync failed.', [
                'server_id' => $server->id,
                'code' => $result['code'],
                'message' => $result['message'],
            ]);

            return [
                'success' => false,
                'code' => $result['code'],
                'message' => $result['message'],
                'total' => 0,
                'added' => 0,
                'removed' => 0,
                'warnings' => $result['warnings'],
            ];
        }

        $tools = $result['tools'];

        $counts = DB::transaction(fn () => $this->reconcileTools($server, $tools));

        $server->last_sync_status = McpSyncStatus::Success;
        $server->last_sync_error = null;
        $server->last_synced_at = now();
        $server->save();

        return [
            'success' => true,
            'code' => $result['code'] ?: 'list_tools.succeeded',
            'message' => __('mcp.messages.sync_succeeded', [
                'total' => $counts['total'],
                'added' => $counts['added'],
                'removed' => $counts['removed'],
            ]),
            'total' => $counts['total'],
            'added' => $counts['added'],
            'removed' => $counts['removed'],
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * 路由入口：需要系统设置编辑权限，结果交给前端直接 toast。
     */
    public function asController(Request $request, string $server): JsonResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $result = $this->handle($systemContext, $server);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'total' => $result['total'],
            'added' => $result['added'],
            'removed' => $result['removed'],
        ]);
    }

    /**
     * 用远端返回的工具列表对账本地 mcp_tools。
     *
     * @param  array<int, array<string, mixed>>  $remoteTools
     * @return array{total: int, added: int, removed: int}
     */
    private function reconcileTools(McpServer $server, array $remoteTools): array
    {
        $now = now();
        $existing = $server->tools()->get()->keyBy('name');
        $seenNames = [];
        $added = 0;

        foreach ($remoteTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $seenNames[$name] = true;

            $description = is_string($tool['description'] ?? null) ? (string) $tool['description'] : null;
            $inputSchema = is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : null;
            $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : null;

            /** @var McpTool|null $current */
            $current = $existing->get($name);

            if ($current === null) {
                $server->tools()->create([
                    'name' => $name,
                    'description' => $description,
                    'input_schema' => $inputSchema,
                    'annotations' => $annotations,
                    'last_seen_at' => $now,
                    'removed_at' => null,
                ]);
                $added++;

                continue;
            }

            $current->description = $description;
            $current->input_schema = $inputSchema;
            $current->annotations = $annotations;
            $current->last_seen_at = $now;
            $current->removed_at = null;
            $current->save();
        }

        $removed = 0;
        foreach ($existing as $name => $tool) {
            if (isset($seenNames[$name])) {
                continue;
            }
            if ($tool->removed_at !== null) {
                continue;
            }
            $tool->removed_at = $now;
            $tool->save();
            $removed++;
        }

        return [
            'total' => count($remoteTools),
            'added' => $added,
            'removed' => $removed,
        ];
    }
}
