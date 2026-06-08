<?php

namespace App\Data\Reception\Plan;

use App\Data\AiRuntime\AiModelOptionData;
use App\Data\EnumOptionData;
use App\Data\Reception\ServiceScenario\PlanKnowledgeBaseOptionData;
use App\Data\Reception\ServiceScenario\PlanMcpToolOptionData;
use App\Data\Reception\ServiceScenario\ServiceScenarioTemplateData;
use Spatie\LaravelData\Data;

/**
 * 接待方案详情（编辑）页 props。
 * 由 ShowReceptionPlanDetailPageAction 返回，下发给 resources/js/pages/reception/plans/Detail.vue。
 * 承载单个方案的完整配置（含服务场景 / 方案级 KB / MCP 工具）及表单所需的全部选项。
 * 保存即发布：编辑保存自动产出版本快照，版本对运营隐藏。
 *
 * llm_model_options / knowledge_base_options / mcp_tool_options / service_scenario_templates 用于：
 *  - 基础信息表单的下拉选项
 *  - 服务场景 Dialog 的"使用模板"入口
 */
class ShowReceptionPlanDetailPagePropsData extends Data
{
    public function __construct(
        public ReceptionPlanData $plan,
        /** @var AiModelOptionData[] */
        public array $llm_model_options,
        /** @var EnumOptionData[] */
        public array $persona_tone_options,
        /** @var EnumOptionData[] */
        public array $message_translation_failure_mode_options,
        /** @var PlanKnowledgeBaseOptionData[] */
        public array $knowledge_base_options,
        /** @var PlanMcpToolOptionData[] */
        public array $mcp_tool_options,
        /** @var ServiceScenarioTemplateData[] */
        public array $service_scenario_templates,
    ) {}
}
