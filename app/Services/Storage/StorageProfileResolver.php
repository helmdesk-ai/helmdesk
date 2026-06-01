<?php

namespace App\Services\Storage;

use App\Enums\StorageDriver;
use App\Enums\StorageProfileStatus;
use App\Models\StorageProfile;
use App\Settings\StorageSettings;
use Illuminate\Validation\ValidationException;

/**
 * 根据系统存储设置解析新上传应使用的存储配置。
 */
class StorageProfileResolver
{
    /**
     * 注入系统存储设置对象。
     */
    public function __construct(
        private readonly StorageSettings $settings,
    ) {}

    /**
     * 解析新上传应使用的存储配置。
     */
    public function resolveForNewUpload(): StorageProfile
    {
        if (! $this->settings->enabled) {
            return $this->localProfile();
        }

        if (! filled($this->settings->current_profile_id)) {
            throw ValidationException::withMessages([
                'storage' => __('storage_settings.current_profile_required'),
            ]);
        }

        $profile = StorageProfile::query()
            ->where('status', StorageProfileStatus::Active)
            ->find($this->settings->current_profile_id);

        if (! $profile) {
            throw ValidationException::withMessages([
                'storage' => __('storage_settings.current_profile_missing'),
            ]);
        }

        return $profile;
    }

    /**
     * 获取或创建系统内置的本地私有存储配置。
     */
    public function localProfile(): StorageProfile
    {
        return StorageProfile::query()->firstOrCreate(
            [
                'driver' => StorageDriver::Local,
                'provider' => null,
                'name' => 'Local private storage',
            ],
            [
                'status' => StorageProfileStatus::Active,
                'metadata' => ['system' => true],
            ],
        );
    }
}
