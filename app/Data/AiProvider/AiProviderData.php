<?php

namespace App\Data\AiProvider;

use App\Enums\AiProviderProtocol;
use App\Models\AiProvider;
use App\Services\AiProvider\AiProviderCatalog;
use Spatie\LaravelData\Data;

/**
 * 全局 AI 供应商展示数据（纯凭据，不含模型）。
 *
 * 由总后台 AI 供应商管理 Action 组装，传给 resources/js/pages/systemSettings/aiProviders/*：渲染列表与编辑表单。
 * credential_values 仅暴露非 secret 字段明文；secret 字段只下发遮掩值（credential_masks），保证 API Key 不回写前端。
 * 模型在「AI 模型管理」页单独维护。
 */
class AiProviderData extends Data
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $brand_label,
        public bool $is_custom,
        public string $slug,
        public string $name,
        public AiProviderProtocol $protocol,
        public ?string $icon,
        public ?string $base_url,
        /** @var array<int, array<string, mixed>> */
        public array $credential_fields,
        /** @var array<string, string|null> */
        public array $credential_values,
        /** @var array<string, string> */
        public array $credential_masks,
        public bool $has_complete_credentials,
    ) {}

    /**
     * 把 Eloquent 模型转成前端可消费的 DTO，按 secret 字段做凭据脱敏。
     */
    public static function fromModel(AiProvider $provider): self
    {
        $credentials = $provider->credentials;
        $credentialValues = [];
        $credentialMasks = [];

        foreach ($provider->credential_fields as $field) {
            $fieldName = $field['field'] ?? null;
            if (! is_string($fieldName)) {
                continue;
            }

            $value = is_array($credentials) ? ($credentials[$fieldName] ?? null) : null;
            $stringValue = is_scalar($value) ? (string) $value : null;

            if (($field['secret'] ?? false) === true) {
                $credentialValues[$fieldName] = null;

                if (filled($stringValue)) {
                    $credentialMasks[$fieldName] = self::maskCredentialValue($stringValue);
                }

                continue;
            }

            $credentialValues[$fieldName] = $stringValue;
        }

        $protocol = $provider->protocol instanceof AiProviderProtocol
            ? $provider->protocol
            : AiProviderProtocol::from((string) $provider->protocol);

        $catalog = app(AiProviderCatalog::class);
        $baseUrl = $credentialValues['base_uri'] ?? null;
        $hasBrand = $catalog->hasBrand($provider->brand);

        return new self(
            id: $provider->id,
            brand: $provider->brand,
            brand_label: $hasBrand ? $catalog->labelForBrand($provider->brand) : $provider->brand,
            is_custom: $hasBrand && $catalog->isCustomBrand($provider->brand),
            slug: $provider->slug,
            name: $provider->name,
            protocol: $protocol,
            icon: $provider->icon,
            base_url: filled($baseUrl) ? (string) $baseUrl : null,
            credential_fields: $provider->credential_fields,
            credential_values: $credentialValues,
            credential_masks: $credentialMasks,
            has_complete_credentials: $provider->hasCompleteCredentials(),
        );
    }

    /**
     * 把密钥值做首尾保留 + 中间 **** 的遮掩处理，避免在前端泄漏密文。
     */
    private static function maskCredentialValue(string $value): string
    {
        return strlen($value) <= 8
            ? str_repeat('*', max(strlen($value), 4))
            : substr($value, 0, 4).'****'.substr($value, -4);
    }
}
