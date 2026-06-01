<?php

namespace App\Actions\SystemSetting;

use App\Data\GeneralSetting\GeneralSettingsData;
use App\Settings\GeneralSettings;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 读取系统基础设置。
 */
class GetGeneralSettingAction
{
    use AsAction;

    public function __construct(
        public GeneralSettings $settings,
    ) {}

    /**
     * 读取系统基础设置展示数据。
     */
    public function handle(): GeneralSettingsData
    {
        return GeneralSettingsData::from($this->settings->toArray());
    }

    /**
     * 返回系统基础设置页面。
     */
    public function asController(): Response
    {
        return Inertia::render('admin/generalSetting/Index');
    }
}
