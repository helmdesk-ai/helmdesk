<?php

namespace App\Data\StorageSetting;

use App\Enums\StorageProvider;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 检查存储设置表单数据。
 * 来自 resources/js/pages/admin/storageSetting/Index.vue 的操作表单或弹窗提交，后端用它校验本次检查动作。
 */
class FormCheckStorageSettingData extends Data
{
    public function __construct(
        public string $provider,
        public string $key,
        public ?string $secret,
        public string $bucket,
        public string $region,
        public string $endpoint,
        public ?string $url = null,
    ) {}

    public static function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::enum(StorageProvider::class)],
            'key' => ['required', 'string'],
            'secret' => ['nullable', 'string'],
            'bucket' => ['required', 'string'],
            'region' => ['required', 'string'],
            'endpoint' => ['required', 'string', 'url'],
            'url' => ['nullable', 'string', 'url'],
        ];
    }
}
