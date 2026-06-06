<?php

namespace App\Models;

use App\Enums\TranslationProviderType;
use Database\Factories\TranslationProviderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $slug
 * @property string $name
 * @property TranslationProviderType $protocol
 * @property string|null $icon
 * @property string|null $credentials
 * @property array $credential_fields
 * @property array|null $options
 * @property bool $is_builtin
 * @property int $sort_order
 * @property mixed $use_factory
 *
 * @method static \Database\Factories\TranslationProviderFactory<self> factory($count = null, $state = [])
 */
class TranslationProvider extends Model
{
    /**
     * 翻译供应商模型，保存协议、凭据字段和运行参数。
     *
     * 供应商本身不带启用状态：用哪家由接待方案的 translation_config.provider_id 决定。
     */

    /** @use HasFactory<TranslationProviderFactory> */
    use HasFactory, HasUlids;

    protected $table = 'translation_providers';

    protected $guarded = [];

    /**
     * 返回翻译供应商字段的类型转换配置；credentials 用 encrypted:array 保证落库为密文。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol' => TranslationProviderType::class,
            'credentials' => 'encrypted:array',
            'credential_fields' => 'array',
            'options' => 'array',
            'is_builtin' => 'boolean',
        ];
    }

    /**
     * 判断该供应商的所有必填凭据是否都已填写。
     *
     * 接待方案选用该供应商前、设置页只读状态展示都依赖这个判断；凭据不全的 driver 会在运行时失败。
     */
    public function hasCompleteCredentials(): bool
    {
        $credentials = $this->credentials;

        foreach ($this->credential_fields as $field) {
            if (! ($field['required'] ?? false)) {
                continue;
            }

            $fieldName = (string) $field['field'];
            if (! is_array($credentials) || blank($credentials[$fieldName] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 把用户提交的凭据合并到现有凭据上，遵守 secret 字段语义。
     *
     * secret 字段（如 API Key）在用户没改时不会覆盖原值；显式传空才表示删除。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function mergeCredentials(array $input): array
    {
        $merged = $this->credentials ?? [];

        foreach ($this->credential_fields as $field) {
            $fieldName = (string) $field['field'];
            if (! array_key_exists($fieldName, $input)) {
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
