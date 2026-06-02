<?php

namespace App\Data\Translation;

use App\Enums\TranslationProviderType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 创建翻译供应商表单数据。
 *
 * 来自 resources/js/pages/systemSettings/translationProviders/Index.vue 的「添加供应商」表单提交，
 * 后端用它在当前系统下创建一条 translation_providers 记录（凭据留空，等用户后续填）。
 */
class FormCreateTranslationProviderData extends Data
{
    public function __construct(
        public string $name,
        public TranslationProviderType $protocol,
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
            'protocol' => ['required', Rule::enum(TranslationProviderType::class)],
            'configuration' => ['nullable', 'array'],
            'configuration.*' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
