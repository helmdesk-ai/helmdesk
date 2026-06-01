<?php

namespace App\Data\Translation;

use App\Models\TranslationProvider;
use Spatie\LaravelData\Data;

/**
 * 接待方案表单的翻译供应商下拉选项。
 *
 * 由 ShowReceptionPlanIndexPageAction 组装下发给 PlanBasicsForm.vue 的供应商 Select：
 * has_complete_credentials 为 false 时前端可禁用或提示「凭据未配置完整」。
 */
class TranslationProviderOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $protocol_label,
        public bool $has_complete_credentials,
    ) {}

    /**
     * 从翻译供应商模型构建下拉选项。
     */
    public static function fromModel(TranslationProvider $provider): self
    {
        return new self(
            id: (string) $provider->id,
            name: $provider->name,
            protocol_label: $provider->protocol->label(),
            has_complete_credentials: $provider->hasCompleteCredentials(),
        );
    }
}
