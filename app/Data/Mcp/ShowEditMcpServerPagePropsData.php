<?php

namespace App\Data\Mcp;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 编辑 MCP 服务页面 props。
 * 由 ShowEditMcpServerPageAction 下发给 resources/js/pages/systemSettings/mcpServers/Edit.vue。
 */
class ShowEditMcpServerPagePropsData extends Data
{
    public function __construct(
        public McpServerData $server,
        /** @var EnumOptionData[] */
        public array $transport_options,
    ) {}
}
