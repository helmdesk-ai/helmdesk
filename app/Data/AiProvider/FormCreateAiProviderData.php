<?php

namespace App\Data\AiProvider;

use App\Services\AiProvider\AiProviderCatalog;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * 创建 AI 供应商表单数据。
 * 来自 resources/js/pages/workspaceSettings/aiProviders/AddProviderDialog.vue 的提交：
 * 用户从品牌目录选 brand、填展示名称和凭据 configuration，后端据此一步创建供应商。
 *
 * @property array<string, mixed> $configuration
 */
class FormCreateAiProviderData extends Data
{
    public function __construct(
        public string $brand,
        public ?string $name,
        /** @var array<string, mixed> */
        public array $configuration = [],
    ) {}

    /**
     * 校验 brand 取自品牌目录；所有品牌都要求填写展示名称，方便区分多份同品牌配置。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $catalog = app(AiProviderCatalog::class);
        $brands = array_keys($catalog->brands());

        return [
            'brand' => ['required', 'string', Rule::in($brands)],
            'name' => ['required', 'string', 'max:128'],
            'configuration' => ['nullable', 'array'],
        ];
    }
}
