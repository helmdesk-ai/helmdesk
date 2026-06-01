<?php

namespace App\Data\Channel\Web;

use Spatie\LaravelData\Data;

/**
 * 网站渠道访客界面标题栏设置。
 */
class ChannelWebHeaderData extends Data
{
    /**
     * 创建访客界面标题栏配置数据。
     */
    public function __construct(
        public bool $enabled = false,
    ) {}
}
