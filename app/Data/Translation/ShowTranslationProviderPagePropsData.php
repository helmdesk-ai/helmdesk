<?php

namespace App\Data\Translation;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 翻译供应商设置页 props。
 *
 * 由 ShowSystemTranslationProvidersAction 返回，传给
 * resources/js/pages/systemSettings/translationProviders/Index.vue 渲染：
 * - providers：当前系统已配置的翻译供应商列表（含脱敏后的凭据状态）
 * - protocolOptions：用于「添加供应商」下拉，由 TranslationProviderType 通过 EnumOptionData::fromCases() 生成
 */
class ShowTranslationProviderPagePropsData extends Data
{
    public function __construct(
        /** @var TranslationProviderData[] */
        public array $providers,

        /** @var EnumOptionData[] */
        public array $protocolOptions,

        /** @var array<string, array<int, array<string, mixed>>> */
        public array $protocolCredentialFields,
    ) {}
}
