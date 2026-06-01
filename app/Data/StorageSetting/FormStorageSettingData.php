<?php

namespace App\Data\StorageSetting;

use Spatie\LaravelData\Data;

/**
 * 存储设置数据。
 * 由后端读取设置后传给 resources/js/pages/admin/storageSetting/Index.vue，前端用它填充设置表单并展示当前配置。
 */
class FormStorageSettingData extends Data
{
    public function __construct(
        public bool $enabled,
        public ?string $current_profile_id,
    ) {}

    public static function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
            'current_profile_id' => 'nullable|string',
        ];
    }
}
