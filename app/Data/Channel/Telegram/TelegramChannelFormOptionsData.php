<?php

namespace App\Data\Channel\Telegram;

use App\Data\EnumOptionData;
use App\Data\Reception\Plan\ReceptionPlanOptionData;
use Spatie\LaravelData\Data;

/**
 * Telegram 渠道详情页表单选项。
 * 提供接待方案与访客默认语言下拉项，供 Show.vue 基本信息表单使用。
 */
class TelegramChannelFormOptionsData extends Data
{
    /**
     * 创建 Telegram 渠道表单选项数据。
     */
    public function __construct(
        /** @var ReceptionPlanOptionData[] */
        public array $reception_plan_options,
        /** @var EnumOptionData[] */
        public array $reception_language_options,
    ) {}
}
