<?php

namespace App\Actions\Native\Channel\Web;

use App\Actions\Channel\Web\Public\ResolvePublicWebChannelWidgetBootstrapAction;
use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Data\Channel\Web\PublicWidgetBootstrapEnvelopeData;
use App\Enums\ChannelType;
use App\Models\Channel;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Native bridge 入口：解析公开网站渠道小部件启动数据。
 *
 * 业务 Action 负责白名单校验 + 落库；
 * Bridge 这层根据渠道 allowed_embed_hosts 是否限制到具体域名，
 * 把 Go 应该回写的 CORS allow_origin 一并打包返回。
 */
class ResolvePublicWebChannelWidgetBootstrapBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责公开网站渠道小部件启动数据解析的业务 Action。
     */
    public function __construct(
        private readonly ResolvePublicWebChannelWidgetBootstrapAction $resolve,
    ) {}

    /**
     * 解析 Go 小部件 iframe 入口需要的公开网站渠道启动数据。
     */
    public function handle(string $code, ?string $embedHost = null): PublicWidgetBootstrapEnvelopeData
    {
        $channelData = $this->resolve->handle($code, $embedHost);

        return new PublicWidgetBootstrapEnvelopeData(
            channel: $channelData,
            cors_allow_origin: $this->corsDecision($code),
        );
    }

    /**
     * 仅根据渠道是否配置了 allowed_embed_hosts 决策 CORS 头：
     * - 未配置或配置 * → "*"（不限制）
     * - 已配置具体域名 → "match"，Go 端应回写实际访客 Origin 头
     *
     * 业务 Action 已经拒掉了不在白名单中的 host，此处只关心策略。
     */
    private function corsDecision(string $code): string
    {
        $channel = Channel::query()
            ->withTrashed()
            ->where('code', $code)
            ->where('type', ChannelType::Web)
            ->first();

        $settings = $channel?->settings instanceof ChannelWebSettingsData
            ? $channel->settings
            : ChannelWebSettingsData::defaults();

        $allowList = array_values(array_filter(
            (array) ($settings->allowed_embed_hosts ?? []),
            static fn (mixed $entry): bool => is_string($entry) && trim($entry) !== '',
        ));

        return $allowList === [] || in_array('*', $allowList, true) ? '*' : 'match';
    }
}
