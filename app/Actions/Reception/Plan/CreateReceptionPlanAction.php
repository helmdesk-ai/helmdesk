<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\AutoMessagesConfigData;
use App\Data\Reception\FormCreateReceptionPlanData;
use App\Data\Reception\ReceptionMessageTranslationConfigData;
use App\Data\Reception\ReceptionStrategyConfigData;
use App\Data\SystemUserContextData;
use App\Models\ReceptionPlan;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
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
 */
class CreateReceptionPlanAction
{
    use AsAction;

    public function __construct(
        private readonly AiModelResolver $resolver,
        private readonly AutoMessageTemplateRenderer $autoMessageTemplateRenderer,
        private readonly EnsureReceptionPlanVersionAction $ensureReceptionPlanVersion,
    ) {}

    /**
     * 创建方案配置并保证同一系统内方案名称唯一、所选模型合法。
     */
    public function handle(SystemContext $systemContext, FormCreateReceptionPlanData $data): ReceptionPlan
    {
        $name = trim($data->name);
        $this->ensureNameIsAvailable($systemContext, $name);

        $this->resolver->assertActiveLlmModelOrFail($systemContext, $data->reception_ai_model_id, 'reception.messages.invalid_reception_model');
        $this->resolver->assertActiveLlmModelOrFail($systemContext, $data->task_ai_model_id, 'reception.messages.invalid_task_model');

        $receptionModelCandidates = $this->buildModelCandidates(
            $systemContext,
            $data->reception_ai_model_id,
            $data->reception_model_candidates,
            'reception_model_candidates',
        );
        $taskModelCandidates = $this->buildModelCandidates(
            $systemContext,
            $data->task_ai_model_id,
            $data->task_model_candidates,
            'task_model_candidates',
        );
        $autoMessagesConfig = $this->buildAutoMessagesConfig($data->auto_messages_config);
        $translationSettings = ReceptionMessageTranslationConfigData::fromArray($data->translation_config);
        $this->assertTranslationProviderValid($systemContext, $translationSettings);
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
            'reception_config' => [
                'default_model' => ReceptionPlan::buildModelInvocation($data->reception_ai_model_id),
                'model_candidates' => $receptionModelCandidates,
            ],
            'task_config' => [
                'default_model' => ReceptionPlan::buildModelInvocation($data->task_ai_model_id),
                'model_candidates' => $taskModelCandidates,
            ],
            'capabilities' => [],
            'always_on_tools' => [],
            'knowledge_base_ids' => [],
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => $autoMessagesConfig,
            'translation_config' => $translationConfig,
        ]);

        $this->ensureReceptionPlanVersion->handle($systemContext, $plan, Auth::user());

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
    private function assertTranslationProviderValid(SystemContext $systemContext, ReceptionMessageTranslationConfigData $settings): void
    {
        if ($settings->provider_id === null) {
            return;
        }

        $provider = $systemContext->translationProviders()->whereKey($settings->provider_id)->first();

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
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $plan = $this->handle($systemContext, FormCreateReceptionPlanData::from($request));

        return redirect()->route('admin.manage.reception.plans.show', [
            'plan' => $plan->id,
        ]);
    }

    /**
     * 同一系统内方案名称必须唯一。
     */
    private function ensureNameIsAvailable(SystemContext $systemContext, string $name): void
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

    /**
     * 根据提交顺序与 priority 生成运行时可消费的候选模型列表，默认模型固定为 0。
     *
     * @param  list<array<string, mixed>>  $rawCandidates
     * @return list<array{ai_model_id: string, priority: int}>
     */
    private function buildModelCandidates(SystemContext $systemContext, string $primaryModelId, array $rawCandidates, string $field): array
    {
        $seen = [$primaryModelId => true];
        $backups = [];

        foreach ($rawCandidates as $index => $candidate) {
            $modelId = isset($candidate['ai_model_id']) && is_string($candidate['ai_model_id'])
                ? trim($candidate['ai_model_id'])
                : '';

            if ($modelId === '' || isset($seen[$modelId])) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.ai_model_id" => __('reception.messages.invalid_reception_model'),
                ]);
            }

            if (! $this->resolver->isValidActiveLlmModel($systemContext, $modelId)) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.ai_model_id" => __('reception.messages.invalid_reception_model'),
                ]);
            }

            $seen[$modelId] = true;
            $backups[] = [
                'ai_model_id' => $modelId,
                'priority' => isset($candidate['priority']) && is_numeric($candidate['priority'])
                    ? max(1, (int) $candidate['priority'])
                    : $index + 1,
                'index' => $index,
            ];
        }

        usort($backups, static fn (array $a, array $b): int => [$a['priority'], $a['index']] <=> [$b['priority'], $b['index']]);

        $candidates = [['ai_model_id' => $primaryModelId, 'priority' => 0]];
        foreach ($backups as $offset => $backup) {
            $candidates[] = [
                'ai_model_id' => $backup['ai_model_id'],
                'priority' => $offset + 1,
            ];
        }

        return $candidates;
    }
}
