<?php

namespace App\Data\AiProvider;

use Spatie\LaravelData\Data;

/**
 * 更新全局 AI 供应商名称与凭据的表单数据。
 *
 * 来自 resources/js/pages/systemSettings/aiProviders/Edit.vue 的提交；
 * 凭据合并语义同翻译供应商：secret 字段提交空值表示「不变」，明文字段提交空值表示清空（见 AiProvider::mergeCredentials）。
 */
class FormUpdateAiProviderCredentialsData extends Data
{
    public function __construct(
        public string $name,
        /** @var array<string, mixed> */
        public array $configuration = [],
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'configuration' => ['nullable', 'array'],
            'configuration.*' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
