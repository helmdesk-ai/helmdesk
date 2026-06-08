<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\FormUpdateAiProviderCredentialsData;
use App\Enums\UserPermission;
use App\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新全局 AI 供应商的名称与凭据（总后台）。
 *
 * 凭据合并语义：secret 字段提交空值表示「不变」，明文字段提交空值表示清空（见 AiProvider::mergeCredentials）。
 */
class UpdateAiProviderCredentialsAction
{
    use AsAction;

    /**
     * 校验后保存名称，并用 mergeCredentials 合并凭据。
     */
    public function handle(string $providerSlug, FormUpdateAiProviderCredentialsData $data): AiProvider
    {
        $provider = $this->findProvider($providerSlug);
        $this->validateConfiguration($provider, $data->configuration);

        $credentials = $provider->mergeCredentials($data->configuration);

        $provider->name = $data->name;
        $provider->credentials = filled($credentials) ? $credentials : null;
        $provider->save();

        return $provider;
    }

    /**
     * 鉴权后从请求取表单数据并保存，回到列表页。
     */
    public function asController(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $data = FormUpdateAiProviderCredentialsData::from($request);
        $this->handle($provider, $data);

        return redirect()->route('admin.manage.ai.providers.index');
    }

    /**
     * 按 slug 定位供应商。
     */
    private function findProvider(string $slug): AiProvider
    {
        return AiProvider::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * 校验提交的 configuration：对必填字段做 secret-aware 校验
     * （secret 字段为空但库里已有值时，保存后仍满足必填约束）。
     *
     * @param  array<string, mixed>  $configuration
     */
    private function validateConfiguration(AiProvider $provider, array $configuration): void
    {
        $rules = [];
        $attributes = [];

        foreach ($provider->credential_fields as $field) {
            $fieldName = $field['field'] ?? null;
            if (! is_string($fieldName)) {
                continue;
            }

            $rules["configuration.{$fieldName}"] = $this->fieldRules($field);
            $attributes["configuration.{$fieldName}"] = $field['label'] ?? $fieldName;
        }

        $validator = Validator::make(
            ['configuration' => $configuration],
            $rules,
            attributes: $attributes,
        );

        $validator->after(function ($validator) use ($provider, $configuration, $attributes): void {
            foreach ($provider->credential_fields as $field) {
                if (! ($field['required'] ?? false)) {
                    continue;
                }

                $fieldName = $field['field'] ?? null;
                if (! is_string($fieldName)) {
                    continue;
                }

                if ($this->willHaveRequiredValue($provider, $field, $configuration)) {
                    continue;
                }

                $validator->errors()->add(
                    "configuration.{$fieldName}",
                    __('validation.required', ['attribute' => $attributes["configuration.{$fieldName}"] ?? $fieldName])
                );
            }
        });

        $validator->validate();
    }

    /**
     * 按字段类型生成校验规则。
     *
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private function fieldRules(array $field): array
    {
        return match ($field['type'] ?? 'text') {
            'url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            default => ['nullable', 'string', 'max:2048'],
        };
    }

    /**
     * 判断保存后该字段是否会留有可用值。
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $configuration
     */
    private function willHaveRequiredValue(AiProvider $provider, array $field, array $configuration): bool
    {
        $fieldName = $field['field'] ?? null;
        if (! is_string($fieldName)) {
            return false;
        }

        if (! array_key_exists($fieldName, $configuration)) {
            return $this->hasStoredCredentialValue($provider, $fieldName);
        }

        $value = $configuration[$fieldName];
        if (filled($value)) {
            return true;
        }

        if (($field['secret'] ?? false) === true) {
            return $this->hasStoredCredentialValue($provider, $fieldName);
        }

        return false;
    }

    /**
     * 判断库里某字段是否已经有值（用于支持 secret 字段提交空表示不变）。
     */
    private function hasStoredCredentialValue(AiProvider $provider, string $fieldName): bool
    {
        $credentials = $provider->credentials;

        return is_array($credentials) && filled($credentials[$fieldName] ?? null);
    }
}
