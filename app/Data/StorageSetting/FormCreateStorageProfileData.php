<?php

namespace App\Data\StorageSetting;

use App\Enums\StorageProvider;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 创建存储配置表单数据。
 * 来自 resources/js/pages/admin/storageSetting/Index.vue 的新增表单提交，后端用它做校验并写入存储设置相关记录。
 */
class FormCreateStorageProfileData extends Data
{
    public function __construct(
        public string $name,
        public string $provider,
        public string $region,
        public string $endpoint,
        public string $bucket,
        public string $key,
        public string $secret,
        public ?string $url = null,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'provider' => ['required', 'string', Rule::enum(StorageProvider::class)],
            'region' => ['required', 'string'],
            'endpoint' => ['required', 'string', 'url'],
            'bucket' => ['required', 'string'],
            'key' => ['required', 'string'],
            'secret' => ['required', 'string'],
            'url' => ['nullable', 'string', 'url'],
        ];
    }
}
