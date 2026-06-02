<?php

namespace App\Data\Mcp;

use App\Models\McpTool;
use Spatie\LaravelData\Data;

/**
 * 单个 MCP 工具的展示数据。
 * 由 McpServerData::fromModel 装配后随 ShowSystemMcpServersPagePropsData 下发，
 * 用于 resources/js/pages/systemSettings/mcpServers 详情面板的工具列表行渲染。
 */
class McpToolData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        /** @var array<string, mixed>|null */
        public ?array $input_schema,
        /** @var array<string, mixed>|null */
        public ?array $annotations,
        public bool $is_enabled,
        public ?string $last_seen_at,
        public ?string $removed_at,
    ) {}

    /**
     * 从模型构造展示数据；时间字段统一序列化为 ISO 字符串。
     */
    public static function fromModel(McpTool $tool): self
    {
        return new self(
            id: (string) $tool->id,
            name: (string) $tool->name,
            description: $tool->description,
            input_schema: $tool->input_schema,
            annotations: $tool->annotations,
            is_enabled: (bool) $tool->is_enabled,
            last_seen_at: $tool->last_seen_at?->toIso8601String(),
            removed_at: $tool->removed_at?->toIso8601String(),
        );
    }
}
