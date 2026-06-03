<?php

namespace App\Data\Mcp;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 创建 MCP 服务页面 props。
 * 由 ShowCreateMcpServerPageAction 下发给 resources/js/pages/systemSettings/mcpServers/Create.vue。
 */
class ShowCreateMcpServerPagePropsData extends Data
{
    public function __construct(
        /** @var EnumOptionData[] */
        public array $transport_options,
    ) {}
}
