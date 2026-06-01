<?php

namespace App\Data\MailSetting;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 邮箱服务器设置页面 props。
 * 由 ShowMailSettingsPageAction 返回给 resources/js/pages/admin/systemSettings/MailSetting.vue。
 */
class ShowMailSettingsPagePropsData extends Data
{
    public function __construct(
        public MailSettingData $settings,

        /** @var EnumOptionData[] */
        public array $driver_options,
    ) {}
}
