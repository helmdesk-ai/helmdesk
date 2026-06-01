<?php

namespace App\Actions\StorageSetting;

use App\Data\StorageSetting\ShowGetStorageSettingPagePropsData;
use App\Data\StorageSetting\StorageProfileData;
use App\Data\StorageSetting\StorageProviderConfigData;
use App\Data\StorageSetting\StorageSettingData;
use App\Enums\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\StorageProfile;
use App\Settings\StorageSettings;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载系统存储设置和存储配置列表。
 */
class GetStorageSettingAction
{
    use AsAction;

    /**
     * 注入系统存储设置对象。
     */
    public function __construct(public StorageSettings $settings) {}

    /**
     * 组装存储设置页首屏配置、配置列表和供应商选项。
     */
    public function handle(): ShowGetStorageSettingPagePropsData
    {
        $storageSettings = new StorageSettingData(
            enabled: (bool) $this->settings->enabled,
            current_profile_id: $this->settings->current_profile_id,
        );

        $storageProfiles = StorageProfile::query()
            ->where('driver', StorageDriver::S3)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StorageProfile $p) => StorageProfileData::fromModel($p))
            ->all();

        $storageConfig = collect(StorageProvider::cases())
            ->map(fn (StorageProvider $provider) => StorageProviderConfigData::fromProvider($provider))
            ->all();

        return new ShowGetStorageSettingPagePropsData(
            settings: $storageSettings,
            profiles: $storageProfiles,
            providers: $storageConfig,
        );
    }

    /**
     * 返回系统存储设置页面。
     */
    public function asController(): Response
    {
        return Inertia::render('admin/storageSetting/Index', $this->handle()->toArray());
    }
}
