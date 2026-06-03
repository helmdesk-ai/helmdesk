<?php

namespace App\Data\Translation;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 编辑翻译供应商页面 props。
 * 由 ShowEditTranslationProviderPageAction 下发给 resources/js/pages/systemSettings/translationProviders/Edit.vue。
 */
class ShowEditTranslationProviderPagePropsData extends Data
{
    public function __construct(
        public TranslationProviderData $provider,

        /** @var EnumOptionData[] */
        public array $protocol_options,

        /** @var array<string, array<int, array<string, mixed>>> */
        public array $protocol_credential_fields,
    ) {}
}
