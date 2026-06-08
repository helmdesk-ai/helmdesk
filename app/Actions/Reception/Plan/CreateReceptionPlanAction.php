<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\Plan\AutoMessagesConfigData;
use App\Data\Reception\Plan\FormCreateReceptionPlanData;
use App\Data\Reception\Plan\ReceptionMessageTranslationConfigData;
use App\Data\Reception\Plan\ReceptionStrategyConfigData;
use App\Enums\UserPermission;
use App\Models\ReceptionPlan;
use App\Models\TranslationProvider;
use App\Services\Reception\AutoMessageTemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 创建接待方案配置（保存即发布）。
 * 写 reception_plans 行后立即编译并生成初始 PlanVersion 快照（v1），使方案创建后即可被渠道选用。
 * 模型不再由方案选择，运行时按用途从全局池取用。
 */
class CreateReceptionPlanAction
{
    use AsAction;

    public function __construct(
        private readonly AutoMessageTemplateRenderer $autoMessageTemplateRenderer,
        private readonly EnsureReceptionPlanVersionAction $ensureReceptionPlanVersion,
    ) {}

    /**
     * 创建方案配置并保证同一系统内方案名称唯一。
     */
    public function handle(FormCreateReceptionPlanData $data): ReceptionPlan
    {
        $name = trim($data->name);
        $this->ensureNameIsAvailable($name);

        $autoMessagesConfig = $this->buildAutoMessagesConfig($data->auto_messages_config);
        $translationSettings = ReceptionMessageTranslationConfigData::fromArray($data->translation_config);
        $this->assertTranslationProviderValid($translationSettings);
        $translationConfig = $translationSettings->toConfigArray();
        $strategyConfig = ReceptionStrategyConfigData::fromArray($data->strategy_config)->toConfigArray();

        $plan = ReceptionPlan::query()->create([
            'name' => $name,
            'description' => filled($data->description) ? $data->description : null,
            'persona_config' => [
                'display_name' => $data->persona_display_name,
                'tone' => $data->persona_tone,
            ],
            'global_instructions' => filled($data->global_instructions) ? $data->global_instructions : null,
            'reception_config' => [],
            'task_config' => [],
            'capabilities' => [],
            'always_on_tools' => [],
            'knowledge_base_ids' => [],
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => $autoMessagesConfig,
            'translation_config' => $translationConfig,
        ]);

        $this->ensureReceptionPlanVersion->handle($plan, Auth::user());

        return $plan;
    }

    /**
     * 规整自动回复配置，并在保存方案时校验模板变量。
     *
     * @param  array<string, mixed>  $rawConfig
     * @return array<string, array{enabled: bool, message: ?string}>
     */
    private function buildAutoMessagesConfig(array $rawConfig): array
    {
        $config = AutoMessagesConfigData::fromArray($rawConfig);

        foreach ($config->toConfigArray() as $trigger => $item) {
            if ($item['enabled'] && ! filled($item['message'])) {
                throw ValidationException::withMessages([
                    "auto_messages_config.{$trigger}.message" => __('validation.required', ['attribute' => __('reception.fields.auto_message')]),
                ]);
            }

            if (filled($item['message'])) {
                $this->autoMessageTemplateRenderer->render($item['message'], []);
            }
        }

        return $config->toConfigArray();
    }

    /**
     * 校验方案选用的翻译供应商：必须属于本系统且必填凭据齐全。
     * provider_id 为空（未启用翻译）时跳过。
     */
    private function assertTranslationProviderValid(ReceptionMessageTranslationConfigData $settings): void
    {
        if ($settings->provider_id === null) {
            return;
        }

        $provider = TranslationProvider::query()->whereKey($settings->provider_id)->first();

        if ($provider === null || ! $provider->hasCompleteCredentials()) {
            throw ValidationException::withMessages([
                'translation_config.provider_id' => __('reception.messages.translation_provider_invalid'),
            ]);
        }
    }

    /**
     * 接收表单并跳转到新方案的详情页，方便用户继续完善配置。
     */
    public function asController(Request $request): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::ReceptionPlansCreate);

        $plan = $this->handle(FormCreateReceptionPlanData::from($request));

        return redirect()->route('admin.manage.reception.plans.show', [
            'plan' => $plan->id,
        ]);
    }

    /**
     * 同一系统内方案名称必须唯一。
     */
    private function ensureNameIsAvailable(string $name): void
    {
        $exists = ReceptionPlan::query()
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('reception.messages.plan_name_exists'),
            ]);
        }
    }
}
