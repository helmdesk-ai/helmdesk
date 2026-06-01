<?php

namespace App\Data\Translation;

use Spatie\LaravelData\Data;

/**
 * 更新翻译供应商基础信息和凭据的表单数据。
 */
class FormUpdateTranslationProviderData extends Data
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
