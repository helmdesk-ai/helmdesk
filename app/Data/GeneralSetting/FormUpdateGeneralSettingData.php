<?php

namespace App\Data\GeneralSetting;

use Spatie\LaravelData\Data;

/**
 * 系统基础设置表单数据。
 * 来自 resources/js/pages/admin/generalSetting/Index.vue 的保存表单，用于校验并更新全局系统配置。
 */
class FormUpdateGeneralSettingData extends Data
{
    public function __construct(
        public string $base_url,
        public string $name,
        public ?string $logo_id = null,
        public ?string $copyright = null,
        public ?string $icp_record = null,
        public bool $allow_registration = true,
    ) {}

    /**
     * 返回系统基础设置表单校验规则。
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'base_url' => 'required|string|max:255|url',
            'name' => 'required|string|max:255',
            'logo_id' => 'nullable|string|max:500',
            'copyright' => 'nullable|string|max:255',
            'icp_record' => 'nullable|string|max:255',
            'allow_registration' => 'required|boolean',
        ];
    }
}
