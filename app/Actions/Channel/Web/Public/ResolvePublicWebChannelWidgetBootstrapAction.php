<?php

namespace App\Actions\Channel\Web\Public;

use App\Data\Channel\Web\PublicStandaloneChannelData;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Services\Channel\WebChannelEmbedHostGate;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 根据公开 code 和嵌入来源解析网站渠道小部件启动数据。
 *
 * AI 不可用时，小部件仍可加载，访客发起会话并排队待人工接待。
 *
 * 渠道配置了 allowed_embed_hosts 时，会按白名单校验来源主机；
 * 命中后会顺便把 first/last embed host/at 写入 channels 行，便于装机健康度观察。
 *
 * 渠道被软删除时返回 paused=true 的最小化数据，用于渲染品牌化的"暂时不可用"占位；
 * 此时跳过 host 白名单校验和 first/last embed 落库。
 */
class ResolvePublicWebChannelWidgetBootstrapAction
{
    use AsAction;

    public function __construct(
        private readonly WebChannelEmbedHostGate $embedHostGate,
    ) {}

    /**
     * 通过公开渠道 code 生成小部件启动数据。
     */
    public function handle(string $code, ?string $embedHost = null): PublicStandaloneChannelData
    {
        $channel = Channel::query()
            ->withTrashed()
            ->where('code', $code)
            ->where('type', ChannelType::Web)
            ->with(['receptionPlanVersion.plan'])
            ->first();

        if ($channel === null) {
            throw new NotFoundHttpException;
        }

        if (! $channel->trashed()) {
            $this->embedHostGate->guard($channel, $embedHost);
            $this->embedHostGate->record($channel, $embedHost);
        }

        return PublicStandaloneChannelData::fromModel(
            $channel,
            useWidgetSettings: true,
            paused: $channel->trashed(),
        );
    }
}
