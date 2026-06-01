<?php

namespace App\Data\Channel\Web;

use App\Models\Channel;
use App\Services\SystemSetting\SystemBaseUrl;
use Spatie\LaravelData\Data;

/**
 * 网站渠道嵌入代码数据。
 * 显示在渠道详情的组件配置页，前端把 script 地址和初始化参数展示给管理员复制。
 */
class WebChannelEmbedData extends Data
{
    /**
     * 创建网站渠道嵌入信息数据。
     */
    public function __construct(
        public string $standalone_url,
        public string $widget_snippet,
    ) {}

    /**
     * 从渠道生成独立页地址和小部件安装代码，主机地址读取后台系统设置中的 base_url。
     */
    public static function fromChannel(Channel $channel): self
    {
        $appUrl = app(SystemBaseUrl::class)->value();

        return new self(
            standalone_url: $appUrl.'/ch/'.$channel->code,
            widget_snippet: "<script async src=\"{$appUrl}/embed/widget.js\" data-channel-code=\"{$channel->code}\"></script>",
        );
    }
}
