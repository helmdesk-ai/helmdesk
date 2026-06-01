<?php

namespace App\Data\StorageSetting;

use Spatie\LaravelData\Data;

/**
 * 编辑存储配置页面 props。
 * 由 ShowEditStorageProfilePageAction 返回给 resources/js/pages/admin/storageSetting/Edit.vue，
 * 用于渲染待编辑的存储配置以及表单的可选区域、Endpoint 联动等下拉选项。
 */
class ShowEditStorageProfilePagePropsData extends Data
{
    public function __construct(
        public StorageProfileData $profile,

        /** @var StorageProviderConfigData[] */
        public array $providers,
    ) {}
}
