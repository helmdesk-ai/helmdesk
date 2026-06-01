<?php

namespace App\Services\Reception;

use App\Data\Reception\ReceptionStrategyConfigData;
use App\Models\Channel;
use LogicException;

/**
 * 从渠道绑定方案的最新已发布版本读取流程策略。
 */
class ReceptionPlanStrategyResolver
{
    /**
     * 注入版本解析器以获取渠道绑定方案的最新已发布版本。
     */
    public function __construct(
        private readonly ChannelActivePlanVersionResolver $activePlanVersionResolver,
    ) {}

    /**
     * 返回渠道绑定方案最新已发布版本中的接待策略配置。
     */
    public function forChannel(Channel $channel): ReceptionStrategyConfigData
    {
        $version = $this->activePlanVersionResolver->currentVersionForChannel($channel);

        if ($version === null) {
            throw new LogicException('Web channel must reference a reception plan with a published version.');
        }

        $snapshot = is_array($version->snapshot_config) ? $version->snapshot_config : [];
        $config = $snapshot['strategy_config'] ?? null;
        if (! is_array($config)) {
            throw new LogicException('Reception plan snapshot must contain strategy_config.');
        }

        return ReceptionStrategyConfigData::fromArray($config);
    }
}
