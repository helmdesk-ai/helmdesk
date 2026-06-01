<?php

namespace App\Data\Reception\ServiceScenario;

use App\Models\McpTool;
use Spatie\LaravelData\Data;

/**
 * 接待方案级 MCP 工具多选项数据。
 * 由 ShowReceptionPlanIndexPageAction 一次性下发给 Index.vue，
 * 用于方案级 MCP 工具配置的多选组件。
 */
class PlanMcpToolOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $server_id,
        public string $server_name,
    ) {}

    /**
     * 从已加载 server 关系的 McpTool 行构造选项。
     */
    public static function fromModel(McpTool $tool): self
    {
        return new self(
            id: (string) $tool->id,
            name: $tool->name,
            description: filled($tool->description) ? $tool->description : null,
            server_id: (string) $tool->mcp_server_id,
            server_name: $tool->server?->name ?? '',
        );
    }
}
