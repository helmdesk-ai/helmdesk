<?php

namespace App\Actions\AiProvider;

use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\AiProvider;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 保存系统内 AI 供应商凭据。
 */
class UpdateAiProviderCredentialsAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function handle(SystemContext $systemContext, string $providerSlug, array $configuration, bool $allowEndpointUpdate = false): AiProvider
    {
        $provider = $this->findProvider($systemContext, $providerSlug);
        $configuration = $this->withoutLockedEndpointConfiguration($provider, $configuration, $allowEndpointUpdate);
        $this->validateConfiguration($provider, $configuration);

        $credentials = $provider->mergeCredentials($configuration);

        $provider->credentials = filled($credentials) ? $credentials : null;
        $provider->save();

        return $provider;
    }

    public function asController(Request $request, string $provider)
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $configuration = $request->input('configuration');
        $this->handle($systemContext, $provider, is_array($configuration) ? $configuration : []);

        return back();
    }

    private function findProvider(SystemContext $systemContext, string $slug): AiProvider
    {
        return $systemContext->aiProviders()->where('slug', $slug)->firstOrFail();
    }

    /**
     * 创建后端点不允许再改；需要更换 endpoint 时应重新添加供应商。
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function withoutLockedEndpointConfiguration(AiProvider $provider, array $configuration, bool $allowEndpointUpdate): array
    {
        if ($allowEndpointUpdate || ! array_key_exists('base_uri', $configuration)) {
            return $configuration;
        }

        $credentials = $provider->credentials;
        $stored = is_array($credentials) && is_scalar($credentials['base_uri'] ?? null)
            ? trim((string) $credentials['base_uri'])
            : '';
        $incoming = is_scalar($configuration['base_uri'])
            ? trim((string) $configuration['base_uri'])
            : '';

        if ($incoming !== '' && $incoming !== $stored) {
            throw ValidationException::withMessages([
                'configuration.base_uri' => __('validation.prohibited', ['attribute' => 'Base URI']),
            ]);
        }

        unset($configuration['base_uri']);

        return $configuration;
    }

    /**
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
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private function fieldRules(array $field): array
    {
        $rules = ['nullable', 'string', 'max:2048'];

        return match ($field['type'] ?? 'text') {
            'url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'select' => [
                'nullable',
                'string',
                Rule::in(
                    collect($field['options'] ?? [])
                        ->pluck('value')
                        ->filter(fn (mixed $value): bool => is_string($value))
                        ->all()
                ),
            ],
            default => $rules,
        };
    }

    /**
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

    private function hasStoredCredentialValue(AiProvider $provider, string $fieldName): bool
    {
        $credentials = $provider->credentials;

        return is_array($credentials) && filled($credentials[$fieldName] ?? null);
    }
}
