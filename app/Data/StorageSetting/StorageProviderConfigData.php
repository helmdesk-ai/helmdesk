<?php

namespace App\Data\StorageSetting;

use App\Data\EnumOptionData;
use App\Enums\StorageProvider;
use Spatie\LaravelData\Data;

/**
 * 存储供应商配置数据。
 * 由后端组装后传给 resources/js/pages/admin/storageSetting/Index.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class StorageProviderConfigData extends Data
{
    public function __construct(
        public EnumOptionData $provider,
        public string $helpLink,
        /** @var StorageRegionData[] */
        public array $regions,
    ) {}

    public static function fromProvider(StorageProvider $provider): self
    {
        $regions = array_map(
            static fn (array $region) => StorageRegionData::from($region),
            $provider->getRegions(),
        );

        return new self(
            provider: EnumOptionData::fromEnum($provider),
            helpLink: $provider->getHelpLink(),
            regions: $regions,
        );
    }
}
