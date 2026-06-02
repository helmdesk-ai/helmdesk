<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\AiProviderData;
use App\Data\AiProvider\BrandOptionData;
use App\Data\AiProvider\ShowAiProviderPagePropsData;
use App\Data\SystemUserContextData;
use App\Models\AiProvider;
use App\Models\SystemContext;
use App\Services\AiProvider\AiProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载当前系统下的 AI 供应商、模型列表与可新增的品牌目录。
 */
class ShowSystemAiProvidersAction
{
    use AsAction;

    public function __construct(
        private readonly AiProviderCatalog $catalog,
    ) {}

    public function handle(SystemContext $systemContext): ShowAiProviderPagePropsData
    {
        $providers = $systemContext->aiProviders()
            ->with([
                'models' => fn ($q) => $q
                    ->orderBy('sort_order'),
            ])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (AiProvider $p) => AiProviderData::fromModel($p))
            ->all();

        $brandOptions = array_map(
            fn (array $option): BrandOptionData => BrandOptionData::from($option),
            $this->catalog->brandOptions(),
        );

        return new ShowAiProviderPagePropsData(
            providers: $providers,
            brandOptions: $brandOptions,
        );
    }

    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        return Inertia::render('systemSettings/aiProviders/Index', $this->handle($systemContext)->toArray());
    }
}
