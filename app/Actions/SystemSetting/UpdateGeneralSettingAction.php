<?php

namespace App\Actions\SystemSetting;

use App\Data\GeneralSetting\FormUpdateGeneralSettingData;
use App\Settings\GeneralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新系统基础设置。
 *
 * base_url 等直接落库即可；对外地址由 SystemBaseUrl 在使用处从 settings 现读，
 * 无需在保存时回填 config('app.url')。
 */
class UpdateGeneralSettingAction
{
    use AsAction;

    public function __construct(
        public GeneralSettings $settings,
    ) {}

    /**
     * 保存系统基础设置表单数据。
     */
    public function handle(FormUpdateGeneralSettingData $data): void
    {
        $this->settings->lock('version');
        $this->settings
            ->fill([
                'base_url' => $data->base_url,
                'name' => $data->name,
                'logo_id' => $data->logo_id,
                'copyright' => $data->copyright,
                'icp_record' => $data->icp_record,
                'allow_registration' => $data->allow_registration,
            ])
            ->save();
    }

    /**
     * 接收系统基础设置保存请求并返回上一页。
     */
    public function asController(Request $request): RedirectResponse
    {
        $this->handle(FormUpdateGeneralSettingData::from($request));

        return back();
    }
}
