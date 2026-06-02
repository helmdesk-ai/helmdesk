<?php

namespace App\Actions\Translation;

use App\Data\SystemUserContextData;
use App\Data\Translation\FormCreateTranslationProviderData;
use App\Models\SystemContext;
use App\Models\TranslationProvider;
use App\Services\Translation\TranslationProviderCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 在当前系统下创建一条翻译供应商记录。
 */
class CreateTranslationProviderAction
{
    use AsAction;

    /**
     * 注入 Catalog 以拿到协议对应的凭据字段定义和默认值。
     */
    public function __construct(
        public TranslationProviderCatalog $catalog,
    ) {}

    /**
     * 落库一条新的翻译供应商记录。
     */
    public function handle(SystemContext $systemContext, FormCreateTranslationProviderData $data): TranslationProvider
    {
        $maxSort = $systemContext->translationProviders()->max('sort_order') ?? 0;
        $defaultConfiguration = $this->catalog->defaultConfigurationForProtocol($data->protocol);
        $credentialFields = $this->catalog->credentialFieldsForProtocol($data->protocol);
        $credentials = $this->buildCredentials($credentialFields, [
            ...$defaultConfiguration,
            ...$data->configuration,
        ]);

        return $systemContext->translationProviders()->create([
            'slug' => Str::slug($data->name).'-'.Str::random(6),
            'name' => $data->name,
            'protocol' => $data->protocol,
            'icon' => $this->catalog->iconForProtocol($data->protocol),
            'credentials' => filled($credentials) ? $credentials : null,
            'credential_fields' => $credentialFields,
            'is_builtin' => false,
            'sort_order' => $maxSort + 1,
        ]);
    }

    /**
     * 校验表单并落库后回到上一页（设置页）。
     */
    public function asController(Request $request): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $data = FormCreateTranslationProviderData::from($request);
        $this->handle($systemContext, $data);

        return back();
    }

    /**
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
