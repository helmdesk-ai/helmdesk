<?php

namespace App\Data\Channel\Telegram;

use App\Data\Reception\Plan\ReceptionPlanOptionData;
use Spatie\LaravelData\Data;

/**
 * 创建 Telegram 渠道页面 props。
 * 由 ShowCreateTelegramChannelPageAction 返回给 resources/js/pages/channel/telegram/Create.vue。
 */
class ShowCreateTelegramChannelPagePropsData extends Data
{
    /**
     * 创建 Telegram 渠道创建页 props。
     */
    public function __construct(
        /** @var ReceptionPlanOptionData[] */
        public array $reception_plan_options,
    ) {}
}
