<?php

namespace App\Actions\Native\Channel\Web;

use App\Actions\Channel\Web\Public\ResolvePublicWebChannelBootstrapAction;
use App\Data\Channel\Web\PublicStandaloneChannelData;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Native bridge 入口：解析公开网站渠道独立页启动数据。
 */
class ResolvePublicWebChannelBootstrapBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责公开网站渠道启动数据解析的业务 Action。
     */
    public function __construct(
        private readonly ResolvePublicWebChannelBootstrapAction $resolvePublicWebChannelBootstrapAction,
    ) {}

    /**
     * 解析 Go 独立页入口需要的公开网站渠道启动数据。
     */
    public function handle(string $code): PublicStandaloneChannelData
    {
        return $this->resolvePublicWebChannelBootstrapAction->handle($code);
    }
}
