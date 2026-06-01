<?php

namespace App\Actions\SystemSetting;

use App\Data\EnumOptionData;
use App\Data\MailSetting\MailSettingData;
use App\Data\MailSetting\ShowMailSettingsPagePropsData;
use App\Enums\MailDriver;
use App\Settings\MailSettings;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示系统设置里的邮件服务器页面。
 */
class ShowMailSettingsPageAction
{
    use AsAction;

    public function __construct(
        public MailSettings $settings,
    ) {}

    public function handle(): ShowMailSettingsPagePropsData
    {
        return new ShowMailSettingsPagePropsData(
            settings: MailSettingData::fromSettings($this->settings),
            driver_options: EnumOptionData::fromCases(MailDriver::cases()),
        );
    }

    public function asController(): Response
    {
        return Inertia::render('admin/systemSettings/MailSetting', $this->handle()->toArray());
    }
}
