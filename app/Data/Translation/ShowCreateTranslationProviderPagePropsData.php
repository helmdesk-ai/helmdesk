<?php

namespace App\Data\Translation;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 创建翻译供应商页面 props。
 * 由 ShowCreateTranslationProviderPageAction 下发给 resources/js/pages/systemSettings/translationProviders/Create.vue。
 */
class ShowCreateTranslationProviderPagePropsData extends Data
{
    public function __construct(
        /** @var EnumOptionData[] */
        public array $protocol_options,

        /** @var array<string, array<int, array<string, mixed>>> */
        public array $protocol_credential_fields,
    ) {}
}
