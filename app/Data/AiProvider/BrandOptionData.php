<?php

namespace App\Data\AiProvider;

use Spatie\LaravelData\Data;

/**
 * 品牌目录项数据。
 * 传给 resources/js/pages/workspaceSettings/aiProviders/AddProviderDialog.vue，
 * 渲染「新增供应商」时可选的品牌列表（含图标与凭据字段，自定义品牌额外要求填名称）。
 */
class BrandOptionData extends Data
{
    public function __construct(
        public string $brand,
        public string $label,
        public ?string $icon,
        public bool $is_custom,
        /** @var array<int, array<string, mixed>> */
        public array $credential_fields,
    ) {}
}
