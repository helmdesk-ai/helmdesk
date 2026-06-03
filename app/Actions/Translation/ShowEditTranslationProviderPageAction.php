<?php

namespace App\Actions\Translation;

use App\Data\EnumOptionData;
use App\Data\SystemUserContextData;
use App\Data\Translation\ShowEditTranslationProviderPagePropsData;
use App\Data\Translation\TranslationProviderData;
use App\Enums\TranslationProviderType;
use App\Enums\UserPermission;
use App\Models\SystemContext;
use App\Services\Translation\TranslationProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 打开编辑翻译供应商页面并下发表单初始值。
 */
class ShowEditTranslationProviderPageAction
{
    use AsAction;

    /**
     * 注入翻译供应商目录。
     */
    public function __construct(
        private readonly TranslationProviderCatalog $catalog,
    ) {}

    /**
     * 组装编辑翻译供应商页面 props。
     */
    public function handle(SystemContext $systemContext, string $slug): ShowEditTranslationProviderPagePropsData
    {
        $provider = $systemContext->translationProviders()
            ->where('slug', $slug)
            ->firstOrFail();

        return new ShowEditTranslationProviderPagePropsData(
            provider: TranslationProviderData::fromModel($provider),
            protocol_options: EnumOptionData::fromCases(TranslationProviderType::cases()),
            protocol_credential_fields: $this->catalog->credentialFieldsByProtocol(),
        );
    }

    /**
     * 渲染编辑翻译供应商页面。
     */
    public function asController(Request $request, string $provider): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        return Inertia::render('systemSettings/translationProviders/Edit', $this->handle($systemContext, $provider)->toArray());
    }
}
