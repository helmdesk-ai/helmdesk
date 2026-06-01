<?php

namespace App\Data\StorageSetting;

use Spatie\LaravelData\Data;

/**
 * 更新存储配置表单数据。
 * 仅允许更新展示名称、可选自定义域名和访问凭据，
 * 其余连接参数在创建后保持不变。
 */
class FormUpdateStorageProfileData extends Data
{
    public function __construct(
        public string $name,
        public ?string $url = null,
        public ?string $key = null,
        public ?string $secret = null,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'url'],
            'key' => ['nullable', 'string'],
            'secret' => ['nullable', 'string'],
        ];
    }
}
