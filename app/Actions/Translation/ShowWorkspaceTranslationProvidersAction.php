<?php

namespace App\Actions\Translation;

use App\Data\EnumOptionData;
use App\Data\Translation\ShowTranslationProviderPagePropsData;
use App\Data\Translation\TranslationProviderData;
use App\Data\WorkspaceUserContextData;
use App\Enums\TranslationProviderType;
use App\Models\TranslationProvider;
use App\Models\Workspace;
use App\Services\Translation\TranslationProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载当前工作区翻译供应商设置页的展示数据。
 *
 * 渲染 resources/js/pages/workspaceSettings/translationProviders/Index.vue：
 * 给出已配置的 provider 列表（凭据脱敏后）和可添加协议下拉。
 */
class ShowWorkspaceTranslationProvidersAction
{
    use AsAction;

    public function __construct(
        private readonly TranslationProviderCatalog $catalog,
    ) {}

    /**
     * 组装供应商列表 + 协议下拉的页面 props。
     */
    public function handle(Workspace $workspace): ShowTranslationProviderPagePropsData
    {
        $providers = $workspace->translationProviders()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TranslationProvider $p) => TranslationProviderData::fromModel($p))
            ->all();

        return new ShowTranslationProviderPagePropsData(
            providers: $providers,
            protocolOptions: EnumOptionData::fromCases(TranslationProviderType::cases()),
            protocolCredentialFields: $this->credentialFieldsByProtocol(),
        );
    }

    /**
     * 解析当前工作区，鉴权后渲染 Inertia 页面。
     */
    public function asController(Request $request): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        return Inertia::render('workspaceSettings/translationProviders/Index', $this->handle($workspace)->toArray());
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function credentialFieldsByProtocol(): array
    {
        $fields = [];

        foreach (TranslationProviderType::cases() as $protocol) {
            $fields[$protocol->value] = $this->catalog->credentialFieldsForProtocol($protocol);
        }

        return $fields;
    }
}
