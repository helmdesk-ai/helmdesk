<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * MCP 服务的传输协议。
 * v1 仅支持 Streamable HTTP；Stdio 等保留位，后续按需扩展。
 */
enum McpTransport: string implements LabeledEnum
{
    case StreamableHttp = 'streamable_http';

    /**
     * 返回传输协议的展示文案。
     */
    public function label(): string
    {
        return match ($this) {
            self::StreamableHttp => __('mcp.transports.streamable_http'),
        };
    }
}
