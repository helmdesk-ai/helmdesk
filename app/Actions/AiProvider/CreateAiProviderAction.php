<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\FormCreateAiProviderData;
use App\Enums\UserPermission;
use App\Models\AiProvider;
use App\Services\AiProvider\AiProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 从品牌目录一步创建系统 AI 供应商：写入协议/图标/凭据字段，校验并保存凭据，带上该品牌的内置模型。
 */
class CreateAiProviderAction
{
    use AsAction;

    public function __construct(
        private readonly AiProviderCatalog $catalog,
    ) {}

    public function handle(FormCreateAiProviderData $data): AiProvider
    {
        $brand = $this->catalog->brand($data->brand);
        $isCustom = (bool) ($brand['is_custom'] ?? false);
        $name = filled($data->name) ? trim((string) $data->name) : (string) $brand['label'];

        return DB::transaction(function () use ($data, $brand, $isCustom, $name) {
            $maxSort = AiProvider::query()->max('sort_order') ?? 0;

            $provider = AiProvider::query()->create([
                'brand' => $data->brand,
                'slug' => Str::slug($data->brand).'-'.Str::random(6),
                'name' => $name,
                'protocol' => $brand['protocol'],
                'icon' => is_string($brand['icon'] ?? null) ? $brand['icon'] : null,
                'credentials' => null,
                'credential_fields' => $brand['credential_fields'],
                'is_builtin' => ! $isCustom,
                'sort_order' => $maxSort + 1,
            ]);

            // 预置品牌默认凭据（如 base_uri），并入用户填写后由凭据 Action 统一校验必填并保存
            $configuration = array_merge(
                $this->catalog->defaultConfigurationForBrand($data->brand),
                $data->configuration,
            );
            UpdateAiProviderCredentialsAction::run($provider->slug, $configuration, allowEndpointUpdate: true);

            foreach ($this->catalog->defaultModelsForBrand($data->brand) as $index => $model) {
                $provider->models()->create([
                    'model_id' => $model['model_id'],
                    'name' => $model['name'],
                    'type' => $model['type'],
                    'is_active' => true,
                    'is_builtin' => true,
                    'sort_order' => $index,
                ]);
            }

            return $provider->refresh();
        });
    }

    public function asController(Request $request)
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $data = FormCreateAiProviderData::from($request);
        $this->handle($data);

        return back();
    }
}
