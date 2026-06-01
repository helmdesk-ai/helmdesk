<?php

namespace App\Data\Reception;

use App\Data\AiRuntime\ModelSelectionStatusData;
use App\Data\Reception\ServiceScenario\ServiceScenarioData;
use App\Models\ReceptionPlan;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use Spatie\LaravelData\Data;

/**
 * 接待方案展示数据。
 * 由 ShowReceptionPlanIndexPageAction 组装后下发给 resources/js/pages/reception/plans/Index.vue，
 * 同时支撑左侧列表行 / 详情区基础信息 / 服务场景分段使用。
 * 版本快照对运营隐藏（保存即发布、渠道自动跟随最新版），此处不再下发版本信息。
 * capabilities / service_scenarios 字段仅"活跃 view 中的当前选中 plan"才会填充完整内容，
 * 其它列表行（含 trash 视图）下保持为空数组以减小 payload。
 */
class ReceptionPlanData extends Data
{
    public function __construct(
        public string $id,
        public string $workspace_id,
        public string $name,
        public ?string $description,
        public PersonaConfigData $persona_config,
        public ?string $global_instructions,
        public ?ModelInvocationData $reception_model,
        public ModelSelectionStatusData $reception_model_status,
        /** @var list<ModelCandidateData> */
        public array $reception_model_candidates,
        public ?ModelInvocationData $task_model,
        public ?ModelSelectionStatusData $task_model_status,
        /** @var list<ModelCandidateData> */
        public array $task_model_candidates,
        public int $service_scenarios_count,
        public ?string $updated_at,
        public ?string $deleted_at,
        public ReceptionStrategyConfigData $strategy_config,
        public AutoMessagesConfigData $auto_messages_config,
        public ReceptionMessageTranslationConfigData $translation_config,
        /** @var list<string> */
        public array $knowledge_base_ids = [],
        /** @var list<string> */
        public array $mcp_tool_ids = [],
        /** @var list<ServiceScenarioData> */
        public array $service_scenarios = [],
    ) {}

    /**
     * 从 Eloquent 模型组装精简版展示数据（左侧列表 / 回收站行用）。
     */
    public static function fromModel(ReceptionPlan $plan, AiModelResolver $resolver): self
    {
        $receptionConfig = $plan->reception_config ?? [];
        $taskConfig = $plan->task_config ?? [];

        $receptionModel = ModelInvocationData::fromArray($receptionConfig['default_model'] ?? null);
        $receptionModelStatus = $resolver->resolveModelStatus($plan->workspace, $receptionModel?->ai_model_id);
        $receptionModel = $receptionModel !== null
            ? new ModelInvocationData(
                ai_model_id: $receptionModel->ai_model_id,
                label: $receptionModelStatus->label,
            )
            : null;
        $receptionModelCandidates = self::resolveModelCandidates($plan->workspace, $resolver, $receptionConfig, $receptionModel);

        $taskModel = ModelInvocationData::fromArray($taskConfig['default_model'] ?? null);
        $taskModelStatus = $taskModel !== null
            ? $resolver->resolveModelStatus($plan->workspace, $taskModel->ai_model_id)
            : null;
        $taskModel = $taskModel !== null
            ? new ModelInvocationData(
                ai_model_id: $taskModel->ai_model_id,
                label: $taskModelStatus?->label,
            )
            : null;
        $taskModelCandidates = self::resolveModelCandidates($plan->workspace, $resolver, $taskConfig, $taskModel);

        return new self(
            id: (string) $plan->id,
            workspace_id: (string) $plan->workspace_id,
            name: $plan->name,
            description: filled($plan->description) ? $plan->description : null,
            persona_config: PersonaConfigData::fromArray($plan->persona_config),
            global_instructions: filled($plan->global_instructions) ? $plan->global_instructions : null,
            reception_model: $receptionModel,
            reception_model_status: $receptionModelStatus,
            reception_model_candidates: $receptionModelCandidates,
            task_model: $taskModel,
            task_model_status: $taskModelStatus,
            task_model_candidates: $taskModelCandidates,
            service_scenarios_count: count($plan->capabilities),
            updated_at: $plan->updated_at?->toIso8601String(),
            deleted_at: $plan->deleted_at?->toIso8601String(),
            strategy_config: ReceptionStrategyConfigData::fromArray($plan->strategy_config),
            auto_messages_config: AutoMessagesConfigData::fromArray($plan->auto_messages_config),
            translation_config: ReceptionMessageTranslationConfigData::fromArray($plan->translation_config),
        );
    }

    /**
     * 在精简数据基础上补全 knowledge_base_ids / mcp_tool_ids / service_scenarios，
     * 供当前选中 plan 的详情区使用。
     */
    public static function fromModelDetailed(
        ReceptionPlan $plan,
        Workspace $workspace,
        AiModelResolver $resolver,
    ): self {
        $base = self::fromModel($plan, $resolver);

        $serviceScenarios = array_map(
            static fn (array $raw): ServiceScenarioData => ServiceScenarioData::fromRaw($raw),
            $plan->capabilities,
        );

        $base->knowledge_base_ids = $plan->knowledge_base_ids;
        $base->mcp_tool_ids = $plan->always_on_tools;
        $base->service_scenarios = $serviceScenarios;

        return $base;
    }

    /**
     * 从模型设置块中还原默认模型与备用模型候选列表。
     *
     * @param  array<string, mixed>  $modelConfig
     * @return list<ModelCandidateData>
     */
    private static function resolveModelCandidates(
        Workspace $workspace,
        AiModelResolver $resolver,
        array $modelConfig,
        ?ModelInvocationData $defaultModel,
    ): array {
        $rawCandidates = isset($modelConfig['model_candidates']) && is_array($modelConfig['model_candidates'])
            ? $modelConfig['model_candidates']
            : [];

        if ($rawCandidates === [] && $defaultModel !== null) {
            $rawCandidates = [
                [
                    'ai_model_id' => $defaultModel->ai_model_id,
                    'priority' => 0,
                ],
            ];
        }

        $candidates = [];
        foreach ($rawCandidates as $index => $raw) {
            if (! is_array($raw) || ! isset($raw['ai_model_id']) || ! is_string($raw['ai_model_id'])) {
                continue;
            }

            $status = $resolver->resolveModelStatus($workspace, $raw['ai_model_id']);
            $candidates[] = new ModelCandidateData(
                ai_model_id: $raw['ai_model_id'],
                priority: isset($raw['priority']) && is_numeric($raw['priority'])
                    ? (int) $raw['priority']
                    : $index + 1,
                label: $status->label,
                status: $status,
            );
        }

        usort($candidates, static fn (ModelCandidateData $a, ModelCandidateData $b): int => $a->priority <=> $b->priority);

        return $candidates;
    }
}
