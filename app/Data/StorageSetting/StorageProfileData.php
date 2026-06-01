<?php

namespace App\Data\StorageSetting;

use App\Data\EnumOptionData;
use App\Models\StorageProfile;
use Spatie\LaravelData\Data;

/**
 * 存储配置数据。
 * 由后端组装后传给 resources/js/pages/admin/storageSetting/Index.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class StorageProfileData extends Data
{
    /**
     * 承载存储配置列表和编辑页需要的展示字段。
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $driver,
        public string $status,
        public ?EnumOptionData $provider,
        public ?string $bucket,
        public ?string $region,
        public ?string $endpoint,
        public ?string $url,
        public ?string $key_masked,
        public bool $has_secret,
    ) {}

    /**
     * 从存储配置模型创建前端展示数据并遮蔽访问密钥。
     */
    public static function fromModel(StorageProfile $profile): self
    {
        $key = $profile->access_key;
        $masked = null;

        if (filled($key)) {
            $key = (string) $key;
            $masked = strlen($key) <= 8
                ? str_repeat('*', max(strlen($key), 4))
                : substr($key, 0, 4).'****'.substr($key, -4);
        }

        return new self(
            id: (string) $profile->id,
            name: (string) $profile->name,
            driver: $profile->driver->value,
            status: $profile->status->value,
            provider: $profile->provider ? EnumOptionData::fromEnum($profile->provider) : null,
            bucket: $profile->bucket,
            region: $profile->region,
            endpoint: $profile->endpoint,
            url: $profile->public_url,
            key_masked: $masked,
            has_secret: filled($profile->secret_key),
        );
    }
}
