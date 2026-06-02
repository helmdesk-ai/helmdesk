<?php

namespace App\Actions\Translation;

use App\Data\SystemUserContextData;
use App\Data\Translation\FormCheckTranslationProviderData;
use App\Data\Translation\TranslationCheckResultData;
use App\Enums\TranslationProviderType;
use App\Models\SystemContext;
use App\Models\TranslationProvider;
use App\Services\Translation\Exceptions\TranslationException;
use App\Services\Translation\TranslationProviderCatalog;
use App\Services\Translation\TranslatorManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 用一段示例文本测试翻译供应商凭据 + 网络连通。
 *
 * 直接走 TranslatorManager → driver.translate()（不绕 Go bridge），便于在设置页给客户实时回显
 * 「原文 / 译文 / 检测到的源语言 / 延迟 / 字符数」，作为是否能配置完成的最终凭证。
 *
 * 允许调用方临时塞 configuration 覆盖未保存的凭据值，方便用户填了 API Key 但没点保存就先测试。
 */
class CheckTranslationProviderAction
{
    use AsAction;

    /**
     * 注入 Manager 用容器解析 driver；driver 实例化时拿的是临时覆盖凭据后的 provider 副本。
     */
    public function __construct(
        public TranslatorManager $manager,
        public TranslationProviderCatalog $catalog,
    ) {}

    /**
     * 调用 driver 翻译并返回前端友好的结果 DTO；driver 抛出异常时返回失败结果。
     *
     * @param  array<string, mixed>|null  $configuration
     */
    public function handle(
        SystemContext $systemContext,
        string $providerSlug,
        FormCheckTranslationProviderData $data,
        ?array $configuration = null,
    ): TranslationCheckResultData {
        $provider = $this->findProvider($systemContext, $providerSlug);

        // 临时叠加未保存的凭据：直接在内存对象上覆盖，不调 save()。
        // Eloquent 模型按引用传递，driver 实例化时会拿到这份新凭据。
        $hasOverride = is_array($configuration) && $configuration !== [];
        if ($hasOverride) {
            $provider->credentials = $provider->mergeCredentials($configuration);
        }

        try {
            $driver = $this->manager->driverFor($provider, fresh: $hasOverride);
            $result = $driver->translate($data->text, $data->source_lang ?? 'auto', $data->target_lang);

            return new TranslationCheckResultData(
                success: true,
                message: __('translation.check_succeeded'),
                result: $result,
            );
        } catch (TranslationException $e) {
            return new TranslationCheckResultData(
                success: false,
                message: $e->getMessage(),
            );
        }
    }

    /**
     * 鉴权后把请求拆成 FormData + 可选 configuration override，调用 handle() 并把结果以 JSON 形式返回给前端。
     */
    public function asController(Request $request, ?string $provider = null): JsonResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $data = FormCheckTranslationProviderData::from($request);
        $configuration = $request->input('configuration');

        if ($provider === null) {
            return response()->json($this->handleDraft(
                $systemContext,
                $data,
                is_array($configuration) ? $configuration : [],
                $request,
            )->toArray());
        }

        return response()->json($this->handle(
            $systemContext,
            $provider,
            $data,
            is_array($configuration) ? $configuration : null,
        )->toArray());
    }

    /**
     * 按 slug 在当前系统下定位 provider；不存在抛 404。
     */
    private function findProvider(SystemContext $systemContext, string $slug): TranslationProvider
    {
        return $systemContext->translationProviders()->where('slug', $slug)->firstOrFail();
    }

    /**
     * 用表单中尚未保存的协议和凭据构造临时 provider，并执行同一套 driver 检测。
     *
     * @param  array<string, mixed>  $configuration
     */
    private function handleDraft(
        SystemContext $systemContext,
        FormCheckTranslationProviderData $data,
        array $configuration,
        Request $request,
    ): TranslationCheckResultData {
        $validated = $request->validate([
            'protocol' => ['required', Rule::enum(TranslationProviderType::class)],
        ]);

        $protocol = TranslationProviderType::from((string) $validated['protocol']);
        $provider = new TranslationProvider([
            'slug' => 'draft',
            'name' => 'Draft',
            'protocol' => $protocol,
            'icon' => $this->catalog->iconForProtocol($protocol),
            'credentials' => $configuration,
            'credential_fields' => $this->catalog->credentialFieldsForProtocol($protocol),
            'is_builtin' => false,
            'sort_order' => 0,
        ]);
        $provider->id = 'draft-'.$systemContext->id;

        try {
            $driver = $this->manager->driverFor($provider, fresh: true);
            $result = $driver->translate($data->text, $data->source_lang ?? 'auto', $data->target_lang);

            return new TranslationCheckResultData(
                success: true,
                message: __('translation.check_succeeded'),
                result: $result,
            );
        } catch (TranslationException $e) {
            return new TranslationCheckResultData(
                success: false,
                message: $e->getMessage(),
            );
        }
    }
}
