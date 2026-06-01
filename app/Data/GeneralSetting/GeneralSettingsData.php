<?php

namespace App\Data\GeneralSetting;

use App\Models\Attachment;
use Spatie\LaravelData\Data;

/**
 * 基础设置数据。
 * 由后端读取设置后传给 resources/js/pages/admin/generalSetting/Index.vue，前端用它填充设置表单并展示当前配置。
 */
class GeneralSettingsData extends Data
{
    public function __construct(
        public string $base_url,
        public string $name,
        public ?string $logo_id = null,
        public ?string $copyright = null,
        public ?string $icp_record = null,
        public ?string $version = null,
        public bool $allow_registration = true,
        public string $logo_url = '',
    ) {}

    /**
     * 获取当前 Logo 的公开访问地址。
     */
    public function resolvedLogoUrl(): string
    {
        return once(fn () => Attachment::find($this->logo_id)?->full_url ?? asset('images/logo.png'));
    }

    /**
     * 输出基础设置展示数据，并补充 Logo 访问地址。
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['logo_url'] = $this->resolvedLogoUrl();

        return $data;
    }
}
