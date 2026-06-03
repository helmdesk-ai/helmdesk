<?php

namespace App\Actions\Translation;

use App\Data\EnumOptionData;
use App\Data\Translation\ShowCreateTranslationProviderPagePropsData;
use App\Enums\TranslationProviderType;
use App\Enums\UserPermission;
use App\Services\Translation\TranslationProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 打开创建翻译供应商页面并下发表单选项。
 */
class ShowCreateTranslationProviderPageAction
{
    use AsAction;

    /**
     * 注入翻译供应商目录。
     */
    public function __construct(
        private readonly TranslationProviderCatalog $catalog,
    ) {}

    /**
     * 组装创建翻译供应商页面 props。
     */
    public function handle(): ShowCreateTranslationProviderPagePropsData
    {
        return new ShowCreateTranslationProviderPagePropsData(
            protocol_options: EnumOptionData::fromCases(TranslationProviderType::cases()),
            protocol_credential_fields: $this->catalog->credentialFieldsByProtocol(),
        );
    }

    /**
     * 渲染创建翻译供应商页面。
     */
    public function asController(Request $request): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        return Inertia::render('systemSettings/translationProviders/Create', $this->handle()->toArray());
    }
}
