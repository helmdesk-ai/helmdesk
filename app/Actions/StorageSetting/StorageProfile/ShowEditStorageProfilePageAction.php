<?php

namespace App\Actions\StorageSetting\StorageProfile;

use App\Data\StorageSetting\ShowEditStorageProfilePagePropsData;
use App\Data\StorageSetting\StorageProfileData;
use App\Data\StorageSetting\StorageProviderConfigData;
use App\Enums\StorageProvider;
use App\Models\StorageProfile;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染编辑存储配置的独立页面。
 */
class ShowEditStorageProfilePageAction
{
    use AsAction;

    public function handle(StorageProfile $profile): ShowEditStorageProfilePagePropsData
    {
        $providers = collect(StorageProvider::cases())
            ->map(fn (StorageProvider $provider) => StorageProviderConfigData::fromProvider($provider))
            ->all();

        return new ShowEditStorageProfilePagePropsData(
            profile: StorageProfileData::fromModel($profile),
            providers: $providers,
        );
    }

    public function asController(StorageProfile $profile)
    {
        return Inertia::render(
            'admin/storageSetting/Edit',
            $this->handle($profile)->toArray(),
        );
    }
}
