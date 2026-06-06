<?php

namespace App\Models;

use Database\Factories\McpToolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $mcp_server_id
 * @property string $name
 * @property string|null $description
 * @property array|null $input_schema
 * @property array|null $annotations
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $removed_at
 * @property mixed $use_factory
 * @property int|null $servers_count
 * @property-read McpServer $server
 *
 * @method static \Database\Factories\McpToolFactory<self> factory($count = null, $state = [])
 */
class McpTool extends Model
{
    /**
     * MCP 服务下从远端拉取并缓存的工具描述。
     * 每次同步时按 (server_id, name) 增量写入；server 上不再返回的工具不删，
     * 改为置 removed_at 显示"已下线"。
     */

    /** @use HasFactory<McpToolFactory> */
    use HasFactory, HasUlids;

    protected $table = 'mcp_tools';

    protected $guarded = [];

    /**
     * 工具字段类型转换：JSON 子段与时间戳。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'annotations' => 'array',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * 工具所属的 MCP 服务。
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(McpServer::class, 'mcp_server_id');
    }
}
