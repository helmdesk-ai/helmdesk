<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * MCP 服务最近一次同步工具列表的结果状态。
 * 用于服务详情页头的状态文字和重试入口。
 */
enum McpSyncStatus: string implements LabeledEnum
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';

    /**
     * 返回同步状态的展示文案。
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('mcp.sync_statuses.pending'),
            self::Success => __('mcp.sync_statuses.success'),
            self::Failed => __('mcp.sync_statuses.failed'),
        };
    }
}
