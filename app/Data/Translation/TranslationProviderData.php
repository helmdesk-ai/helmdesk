<?php

namespace App\Data\Translation;

use App\Enums\TranslationProviderType;
use App\Models\TranslationProvider;
use App\Services\Translation\TranslationProviderCatalog;
use Spatie\LaravelData\Data;

/**
 * 翻译供应商展示数据。
 *
 * 由 ShowSystemTranslationProvidersAction 组装，传给
 * resources/js/pages/systemSettings/translationProviders/Index.vue 渲染单卡片。
 * credential_values 仅暴露非 secret 字段的明文；secret 字段只下发遮掩值（credential_masks），保证 API Key 不回写到前端。
 * protocol 直接以 Enum 形式下发，protocol_label 同步给出对应展示文案，避免前端手写枚举文案映射。
 * has_complete_credentials 供设置页只读 Badge 标识凭据是否已填齐（决定能否被接待方案选用）。
 */
class TranslationProviderData extends Data
{
    /**
     * 创建翻译供应商展示数据。
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public TranslationProviderType $protocol,
        public string $protocol_label,
        public ?string $icon,
        /** @var array<int, array<string, mixed>> */
        public array $credential_fields,
        /** @var array<string, string|null> */
        public array $credential_values,
        /** @var array<string, string> */
        public array $credential_masks,
        public bool $has_complete_credentials,
        public bool $is_builtin,
        public int $sort_order,
    ) {}

    /**
     * 把 Eloquent 模型转成前端可消费的 DTO，按 secret 字段做凭据脱敏。
     */
    public static function fromModel(TranslationProvider $provider): self
    {
        $credentials = $provider->credentials;
        $credentialValues = [];
        $credentialMasks = [];

        foreach ($provider->credential_fields as $field) {
            $fieldName = (string) $field['field'];

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

        $protocol = $provider->protocol;

        return new self(
            id: $provider->id,
            slug: $provider->slug,
            name: $provider->name,
            protocol: $protocol,
            protocol_label: $protocol->label(),
            icon: $provider->icon
                ?? app(TranslationProviderCatalog::class)->iconForProtocol($protocol),
            credential_fields: $provider->credential_fields,
            credential_values: $credentialValues,
            credential_masks: $credentialMasks,
            has_complete_credentials: $provider->hasCompleteCredentials(),
            is_builtin: $provider->is_builtin,
            sort_order: $provider->sort_order,
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
