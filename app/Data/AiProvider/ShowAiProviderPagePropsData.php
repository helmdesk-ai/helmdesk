<?php

namespace App\Data\AiProvider;

use Spatie\LaravelData\Data;

/**
 * AI 供应商列表页 props。
 * 由 ShowSystemAiProvidersAction 返回给 resources/js/pages/systemSettings/aiProviders/Index.vue，
 * 渲染系统下已添加的供应商与模型，以及「新增供应商」可选的品牌目录。
 */
class ShowAiProviderPagePropsData extends Data
{
    public function __construct(
        /** @var AiProviderData[] */
        public array $providers,

        /** @var BrandOptionData[] */
        public array $brandOptions,
    ) {}
}
