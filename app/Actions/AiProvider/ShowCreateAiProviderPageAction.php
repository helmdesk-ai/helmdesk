<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\BrandOptionData;
use App\Data\AiProvider\ShowCreateAiProviderPagePropsData;
use App\Enums\UserPermission;
use App\Services\AiProvider\AiProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染总后台「新增 AI 供应商」页：给出品牌目录（含图标与各品牌凭据字段）。
 */
class ShowCreateAiProviderPageAction
{
    use AsAction;

    public function __construct(
        private readonly AiProviderCatalog $catalog,
    ) {}

    /**
     * 组装品牌目录选项。
     */
    public function handle(): ShowCreateAiProviderPagePropsData
    {
        $brandOptions = array_map(
            static fn (array $option) => BrandOptionData::from($option),
            $this->catalog->brandOptions(),
        );

        return new ShowCreateAiProviderPagePropsData(brand_options: $brandOptions);
    }

    /**
     * 鉴权后渲染新增页。
     */
    public function asController(Request $request): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        return Inertia::render('systemSettings/aiProviders/Create', $this->handle()->toArray());
    }
}
