<?php

namespace App\Data\Mcp;

use Spatie\LaravelData\Data;

/**
 * 系统 MCP 服务列表页 props。
 * 由 ShowSystemMcpServersAction 返回给 resources/js/pages/systemSettings/mcpServers/Index.vue，
 * 用于渲染列表、工具弹窗和同步状态。
 */
class ShowSystemMcpServersPagePropsData extends Data
{
    public function __construct(
        /** @var McpServerData[] */
        public array $servers,
    ) {}
}
