<?php

namespace App\Actions\Translation;

use App\Data\SystemUserContextData;
use App\Data\Translation\FormUpdateTranslationProviderData;
use App\Enums\UserPermission;
use App\Models\SystemContext;
use App\Models\TranslationProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 保存系统翻译供应商的凭据。
 *
 * 合并语义和 UpdateAiProviderCredentialsAction 对齐：secret 字段提交空值表示"不变"，明文字段提交空值表示清空。
 */
class UpdateTranslationProviderCredentialsAction
{
    use AsAction;

    /**
     * 校验后保存名称，并用 mergeCredentials 合并凭据。
     */
    public function handle(SystemContext $systemContext, string $providerSlug, FormUpdateTranslationProviderData $data): TranslationProvider
    {
        $provider = $this->findProvider($systemContext, $providerSlug);
        $this->validateConfiguration($provider, $data->configuration);

        $credentials = $provider->mergeCredentials($data->configuration);

        $provider->name = $data->name;
        $provider->credentials = filled($credentials) ? $credentials : null;
        $provider->save();

        return $provider;
    }

    /**
     * 从请求取表单数据、校验、保存后回到列表页。
     */
    public function asController(Request $request, string $provider): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $data = FormUpdateTranslationProviderData::from($request);
        $this->handle($systemContext, $provider, $data);

        return redirect()->route('admin.manage.translation.providers.index');
    }

    /**
     * 按 provider 的 credential_fields 动态生成校验规则。
     */
    private function findProvider(SystemContext $systemContext, string $slug): TranslationProvider
    {
        return $systemContext->translationProviders()->where('slug', $slug)->firstOrFail();
    }

    /**
     * 校验提交的 configuration：对必填字段做 secret-aware 校验
     * （secret 字段为空但库里已有值时，保存后仍满足必填约束）。
     *
     * @param  array<string, mixed>  $configuration
     */
    private function validateConfiguration(TranslationProvider $provider, array $configuration): void
    {
        $rules = [];
        $attributes = [];

        foreach ($provider->credential_fields as $field) {
            $fieldName = (string) $field['field'];

            $rules["configuration.{$fieldName}"] = ['nullable', 'string', 'max:2048'];
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

                $fieldName = (string) $field['field'];

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
     * 判断保存后该字段是否会留有可用值。
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $configuration
     */
    private function willHaveRequiredValue(TranslationProvider $provider, array $field, array $configuration): bool
    {
        $fieldName = (string) $field['field'];

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
    private function hasStoredCredentialValue(TranslationProvider $provider, string $fieldName): bool
    {
        $credentials = $provider->credentials;

        return is_array($credentials) && filled($credentials[$fieldName] ?? null);
    }
}
