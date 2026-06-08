<?php

namespace App\Actions\AiProvider;

use App\Data\AiProvider\FormCreateAiProviderData;
use App\Enums\UserPermission;
use App\Models\AiProvider;
use App\Services\AiProvider\AiProviderCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 按品牌创建全局 AI 供应商（总后台，纯凭据）。
 *
 * 对齐翻译供应商：只落一条供应商记录，不再自动播种任何模型；模型在「AI 模型管理」页单独添加。
 */
class CreateAiProviderAction
{
    use AsAction;

    public function __construct(
        private readonly AiProviderCatalog $catalog,
    ) {}

    /**
     * 按品牌拼装协议/图标/凭据字段并落库。
     */
    public function handle(FormCreateAiProviderData $data): AiProvider
    {
        $brand = $data->brand;
        $credentialFields = $this->catalog->credentialFieldsForBrand($brand);
        $name = filled($data->name) ? trim((string) $data->name) : $this->catalog->labelForBrand($brand);
        $credentials = $this->buildCredentials($credentialFields, [
            ...$this->catalog->defaultConfigurationForBrand($brand),
            ...$data->configuration,
        ]);

        return AiProvider::query()->create([
            'brand' => $brand,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'protocol' => $this->catalog->protocolForBrand($brand),
            'icon' => $this->catalog->iconForBrand($brand),
            'credentials' => filled($credentials) ? $credentials : null,
            'credential_fields' => $credentialFields,
        ]);
    }

    /**
     * 鉴权、校验表单并落库后回到列表页。
     */
    public function asController(Request $request): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle(FormCreateAiProviderData::from($request));

        return redirect()->route('admin.manage.ai.providers.index');
    }

    /**
     * 仅保留有值的凭据字段。
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function buildCredentials(array $fields, array $configuration): array
    {
        $credentials = [];

        foreach ($fields as $field) {
            $fieldName = (string) $field['field'];
            $value = $configuration[$fieldName] ?? null;

            if (filled($value)) {
                $credentials[$fieldName] = $value;
            }
        }

        return $credentials;
    }
}
