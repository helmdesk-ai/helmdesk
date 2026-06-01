<?php

namespace App\Actions\StorageSetting\StorageProfile;

use App\Data\StorageSetting\ShowCreateStorageProfilePagePropsData;
use App\Data\StorageSetting\StorageProviderConfigData;
use App\Enums\StorageProvider;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染创建存储配置的独立页面。
 */
class ShowCreateStorageProfilePageAction
{
    use AsAction;

    public function handle(): ShowCreateStorageProfilePagePropsData
    {
        $providers = collect(StorageProvider::cases())
            ->map(fn (StorageProvider $provider) => StorageProviderConfigData::fromProvider($provider))
            ->all();

        return new ShowCreateStorageProfilePagePropsData(
            providers: $providers,
        );
    }

    public function asController()
    {
        return Inertia::render('admin/storageSetting/Create', $this->handle()->toArray());
    }
}
