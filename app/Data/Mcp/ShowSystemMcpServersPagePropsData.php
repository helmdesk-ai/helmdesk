<?php

namespace App\Data\Mcp;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 系统 MCP 服务页 props。
 * 由 ShowSystemMcpServersAction 返回给 resources/js/pages/systemSettings/mcpServers/Index.vue，
 * 用于渲染左侧服务列表 + 右侧详情面板 + 表单选项。
 */
class ShowSystemMcpServersPagePropsData extends Data
{
    public function __construct(
        /** @var McpServerData[] */
        public array $servers,
        /** @var EnumOptionData[] */
        public array $transport_options,
    ) {}
}
