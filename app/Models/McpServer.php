<?php

namespace App\Models;

use App\Enums\McpSyncStatus;
use App\Enums\McpTransport;
use Database\Factories\McpServerFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $workspace_id
 * @property string $slug
 * @property string $name
 * @property McpTransport $transport
 * @property string $endpoint_url
 * @property string|null $credentials
 * @property array|null $headers
 * @property bool $is_active
 * @property int $timeout_seconds
 * @property Carbon|null $last_synced_at
 * @property McpSyncStatus $last_sync_status
 * @property string|null $last_sync_error
 * @property int $sort_order
 * @property mixed $use_factory
 * @property int|null $workspaces_count
 * @property int|null $tools_count
 * @property-read Workspace $workspace
 * @property-read Collection|McpTool[] $tools
 *
 * @method static \Database\Factories\McpServerFactory<self> factory($count = null, $state = [])
 */
class McpServer extends Model
{
    /**
     * 工作区级 MCP 服务注册表。
     * 保存 endpoint / 认证方式 / 加密凭据 / 用户自定义请求头，
     * 以及最近一次同步工具列表的状态。tools 关系是 Go 同步回来的缓存。
     */

    /** @use HasFactory<McpServerFactory> */
    use HasFactory, HasUlids;

    protected $table = 'mcp_servers';

    protected $guarded = [];

    /**
     * MCP 服务字段的类型转换：枚举、加密 JSON、时间戳。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transport' => McpTransport::class,
            'credentials' => 'encrypted:array',
            'headers' => 'array',
            'is_active' => 'boolean',
            'timeout_seconds' => 'integer',
            'last_synced_at' => 'datetime',
            'last_sync_status' => McpSyncStatus::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * MCP 服务所属工作区。
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * MCP 服务最近一次同步缓存下来的工具列表（含已下线）。
     */
    public function tools(): HasMany
    {
        return $this->hasMany(McpTool::class);
    }

    /**
     * 合并表单输入到已存的加密凭据：缺省字段保留原值，显式空值清除。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function mergeCredentials(array $input): array
    {
        $merged = $this->credentials ?? [];

        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($value === null) {
                unset($merged[$key]);

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                unset($merged[$key]);

                continue;
            }

            $merged[$key] = $stringValue;
        }

        return $merged;
    }
}
