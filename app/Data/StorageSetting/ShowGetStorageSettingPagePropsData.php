<?php

namespace App\Data\StorageSetting;

use Spatie\LaravelData\Data;

/**
 * Get存储设置页面 props。
 * 由对应 Show*Action 返回给 resources/js/pages/admin/storageSetting/Index.vue，用于渲染首屏数据、筛选项和页面状态。
 */
class ShowGetStorageSettingPagePropsData extends Data
{
    public function __construct(
        public StorageSettingData $settings,

        /** @var StorageProfileData[] */
        public array $profiles,

        /** @var StorageProviderConfigData[] */
        public array $providers,
    ) {}
}
