<?php

namespace App\Data\StorageSetting;

use Spatie\LaravelData\Data;

/**
 * 创建存储配置页面 props。
 * 由 ShowCreateStorageProfilePageAction 返回给 resources/js/pages/admin/storageSetting/Create.vue，
 * 用于渲染表单的可选区域、Endpoint 联动等下拉选项。
 */
class ShowCreateStorageProfilePagePropsData extends Data
{
    public function __construct(
        /** @var StorageProviderConfigData[] */
        public array $providers,
    ) {}
}
