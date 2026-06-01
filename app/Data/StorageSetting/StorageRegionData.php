<?php

namespace App\Data\StorageSetting;

use Spatie\LaravelData\Data;

/**
 * 存储地域数据。
 * 由后端组装后传给 resources/js/pages/admin/storageSetting/Index.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class StorageRegionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $endpoint,
        public ?string $internalEndpoint = null,
    ) {}
}
