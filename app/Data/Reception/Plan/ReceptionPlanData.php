<?php

namespace App\Data\Reception\Plan;

use App\Data\Reception\ServiceScenario\ServiceScenarioData;
use App\Models\ReceptionPlan;
use Spatie\LaravelData\Data;

/**
 * 接待方案展示数据。
 * 由 ShowReceptionPlanIndexPageAction 组装后下发给 resources/js/pages/reception/plans/Index.vue，
 * 同时支撑左侧列表行 / 详情区基础信息 / 服务场景分段使用。
 * 版本快照对运营隐藏（保存即发布、渠道自动跟随最新版），展示数据只包含方案当前配置。
 * 模型不再由方案选择，运行时按用途从全局池取用，此处不下发模型信息。
 * capabilities / service_scenarios 字段仅"活跃 view 中的当前选中 plan"才会填充完整内容，
 * 其它列表行（含 trash 视图）下保持为空数组以减小 payload。
 */
class ReceptionPlanData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public PersonaConfigData $persona_config,
        public ?string $global_instructions,
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
    public static function fromModel(ReceptionPlan $plan): self
    {
        return new self(
            id: (string) $plan->id,
            name: $plan->name,
            description: filled($plan->description) ? $plan->description : null,
            persona_config: PersonaConfigData::fromArray($plan->persona_config),
            global_instructions: filled($plan->global_instructions) ? $plan->global_instructions : null,
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
    public static function fromModelDetailed(ReceptionPlan $plan): self
    {
        $base = self::fromModel($plan);

        $serviceScenarios = array_map(
            static fn (array $raw): ServiceScenarioData => ServiceScenarioData::fromRaw($raw),
            $plan->capabilities,
        );

        $base->knowledge_base_ids = $plan->knowledge_base_ids;
        $base->mcp_tool_ids = $plan->always_on_tools;
        $base->service_scenarios = $serviceScenarios;

        return $base;
    }
}
