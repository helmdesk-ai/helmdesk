<?php

namespace App\Services\Translation;

use App\Enums\TranslationProviderType;

/**
 * 翻译供应商内置配置目录。
 *
 * 与 AiProviderCatalog 同构：集中维护 protocol → 凭据字段 / 默认值，避免 Action 里散落 if-else。
 */
class TranslationProviderCatalog
{
    /**
     * 返回支持的翻译供应商定义表。
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            TranslationProviderType::GoogleTranslate->value => [
                'label' => 'Google Translate',
                'credential_fields' => [
                    $this->passwordField('api_key', 'API Key'),
                ],
            ],
            TranslationProviderType::DeepL->value => [
                'label' => 'DeepL',
                'credential_fields' => [
                    $this->passwordField('auth_key', 'Auth Key'),
                    $this->urlField('endpoint', 'Endpoint', required: true, default: 'https://api.deepl.com'),
                ],
            ],
            TranslationProviderType::AzureTranslator->value => [
                'label' => 'Microsoft Azure Translator',
                'credential_fields' => [
                    $this->passwordField('api_key', 'API Key'),
                    $this->textField('region', 'Region', required: false),
                    $this->urlField('endpoint', 'Endpoint', required: true, default: 'https://api.cognitive.microsofttranslator.com'),
                ],
            ],
            TranslationProviderType::BaiduTranslate->value => [
                'label' => 'Baidu Translate',
                'credential_fields' => [
                    $this->textField('app_id', 'App ID'),
                    $this->passwordField('app_secret', 'App Secret'),
                    $this->urlField('endpoint', 'Endpoint', required: true, default: 'https://fanyi-api.baidu.com/api/trans/vip/translate'),
                ],
            ],
            TranslationProviderType::TencentCloudTranslate->value => [
                'label' => 'Tencent Cloud Machine Translation',
                'credential_fields' => [
                    $this->passwordField('secret_id', 'Secret ID'),
                    $this->passwordField('secret_key', 'Secret Key'),
                    $this->textField('region', 'Region', default: 'ap-guangzhou'),
                    $this->urlField('endpoint', 'Endpoint', required: true, default: 'https://tmt.tencentcloudapi.com'),
                ],
            ],
            TranslationProviderType::AmazonTranslate->value => [
                'label' => 'Amazon Translate',
                'credential_fields' => [
                    $this->passwordField('access_key_id', 'Access Key ID'),
                    $this->passwordField('secret_access_key', 'Secret Access Key'),
                    $this->passwordField('session_token', 'Session Token', required: false),
                    $this->textField('region', 'Region', default: 'us-east-1'),
                    $this->urlField('endpoint', 'Endpoint', required: false),
                ],
            ],
        ];
    }

    /**
     * 取指定协议需要的凭据字段配置（用于前端渲染表单和后端校验合并）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function credentialFieldsForProtocol(TranslationProviderType $protocol): array
    {
        return $this->definitions()[$protocol->value]['credential_fields'];
    }

    /**
     * 返回前端表单按协议索引的凭据字段配置。
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function credentialFieldsByProtocol(): array
    {
        $fields = [];

        foreach (TranslationProviderType::cases() as $protocol) {
            $fields[$protocol->value] = $this->credentialFieldsForProtocol($protocol);
        }

        return $fields;
    }

    /**
     * 为指定协议拼出默认凭据值（仅填字段定义中显式给了 default 的项）。
     *
     * @return array<string, mixed>
     */
    public function defaultConfigurationForProtocol(TranslationProviderType $protocol): array
    {
        $configuration = [];

        foreach ($this->credentialFieldsForProtocol($protocol) as $field) {
            if (array_key_exists('default', $field) && $field['default'] !== null) {
                $configuration[$field['field']] = $field['default'];
            }
        }

        return $configuration;
    }

    /**
     * 声明一个密钥字段（前端用 type=password 渲染，回显走 mask）。
     *
     * @return array<string, mixed>
     */
    private function passwordField(string $field, string $label, bool $required = true, ?string $default = null): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'type' => 'password',
            'required' => $required,
            'secret' => true,
            'default' => $default,
        ];
    }

    /**
     * 声明普通文本字段。
     *
     * @return array<string, mixed>
     */
    private function textField(string $field, string $label, bool $required = true, ?string $default = null): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'type' => 'text',
            'required' => $required,
            'secret' => false,
            'default' => $default,
        ];
    }

    /**
     * 声明 URL 字段。
     *
     * @return array<string, mixed>
     */
    private function urlField(string $field, string $label, bool $required = true, ?string $default = null): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'type' => 'url',
            'required' => $required,
            'secret' => false,
            'default' => $default,
        ];
    }
}
