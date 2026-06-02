<?php

namespace App\Models;

use App\Enums\AiProviderProtocol;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $brand
 * @property string $slug
 * @property string $name
 * @property AiProviderProtocol $protocol
 * @property string|null $icon
 * @property array $credential_fields
 * @property bool $is_builtin
 * @property int $sort_order
 * @property ?array $credentials
 * @property int|null $models_count
 * @property-read Collection|AiModel[] $models
 */
class AiProvider extends Model
{
    /**
     * AI 供应商模型，保存协议、凭据字段、图标和连接配置。
     */
    use HasUlids;

    protected $table = 'ai_providers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'protocol' => AiProviderProtocol::class,
            'credential_fields' => 'array',
            'is_builtin' => 'boolean',
        ];
    }

    /**
     * @return Attribute<array<string, mixed>|null, array<string, mixed>|null>
     */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?array => $this->readCredentials($value),
            set: fn (mixed $value): ?string => is_array($value)
                ? Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR))
                : null,
        );
    }

    /**
     * 供应商下可用的模型列表。
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCredentials(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return $this->decodeCredentials(Crypt::decryptString($value));
        } catch (DecryptException) {
            return $this->decodeCredentials($value);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeCredentials(string $value): ?array
    {
        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Merge user-supplied input into the stored credentials, respecting secret-field semantics.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function mergeCredentials(array $input): array
    {
        $merged = $this->credentials ?? [];

        foreach ($this->credential_fields as $field) {
            $fieldName = $field['field'] ?? null;
            if (! is_string($fieldName) || ! array_key_exists($fieldName, $input)) {
                continue;
            }

            $value = $input[$fieldName];

            if (($field['secret'] ?? false) === true && blank($value)) {
                continue;
            }

            if (blank($value)) {
                unset($merged[$fieldName]);

                continue;
            }

            $merged[$fieldName] = $value;
        }

        return $merged;
    }
}
