<?php

namespace App\Data\AiProvider;

use App\Enums\AiProviderProtocol;
use App\Models\AiProvider;
use Spatie\LaravelData\Data;

/**
 * AI供应商数据。
 * 由后端组装后传给 resources/js/pages/workspaceSettings/aiProviders/Index.vue，
 * 用于页面展示工作区下的供应商、凭据状态与模型列表。
 */
class AiProviderData extends Data
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $slug,
        public string $name,
        public string $protocol,
        public ?string $icon,
        /** @var array */
        public array $credential_fields,
        /** @var array<string, string|null> */
        public array $credential_values,
        /** @var array<string, string> */
        public array $credential_masks,
        public bool $is_builtin,
        public int $sort_order,
        /** @var AiModelData[] */
        public array $models,
    ) {}

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

        $models = $provider->relationLoaded('models')
            ? $provider->models->map(fn ($m) => AiModelData::fromModel($m))->all()
            : $provider->models()
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($m) => AiModelData::fromModel($m))
                ->all();

        return new self(
            id: $provider->id,
            brand: $provider->brand,
            slug: $provider->slug,
            name: $provider->name,
            protocol: $provider->protocol instanceof AiProviderProtocol ? $provider->protocol->value : $provider->protocol,
            icon: $provider->icon,
            credential_fields: $provider->credential_fields,
            credential_values: $credentialValues,
            credential_masks: $credentialMasks,
            is_builtin: $provider->is_builtin,
            sort_order: $provider->sort_order,
            models: $models,
        );
    }

    private static function maskCredentialValue(string $value): string
    {
        return strlen($value) <= 8
            ? str_repeat('*', max(strlen($value), 4))
            : substr($value, 0, 4).'****'.substr($value, -4);
    }
}
