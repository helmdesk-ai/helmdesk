<?php

namespace App\Actions\Reception\Plan;

use App\Data\EnumOptionData;
use App\Data\Reception\ReceptionPlanData;
use App\Data\Reception\ServiceScenario\PlanKnowledgeBaseOptionData;
use App\Data\Reception\ServiceScenario\PlanMcpToolOptionData;
use App\Data\Reception\ServiceScenario\ServiceScenarioTemplateData;
use App\Data\Reception\ShowReceptionPlanDetailPagePropsData;
use App\Data\Translation\TranslationProviderOptionData;
use App\Data\WorkspaceUserContextData;
use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\ReceptionPersonaTone;
use App\Models\KnowledgeBase;
use App\Models\McpTool;
use App\Models\ReceptionPlan;
use App\Models\TranslationProvider;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use App\Support\Reception\ServiceScenarioTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染接待方案详情（编辑）页（Detail.vue）。
 * 下发单个方案的完整配置（含服务场景 / 方案级 KB / MCP 工具）及表单所需的全部选项；
 * 选项一次性下发，避免 Dialog 打开时再单独请求。保存即发布。
 */
class ShowReceptionPlanDetailPageAction
{
    use AsAction;

    public function __construct(
        private readonly AiModelResolver $resolver,
    ) {}

    /**
     * 组装详情页 props：选中方案 + 表单选项集合。
     */
    public function handle(Workspace $workspace, ReceptionPlan $plan): ShowReceptionPlanDetailPagePropsData
    {
        $plan->setRelation('workspace', $workspace);

        return new ShowReceptionPlanDetailPagePropsData(
            plan: ReceptionPlanData::fromModelDetailed($plan, $workspace, $this->resolver),
            llm_model_options: $this->resolver->getActiveLlmModelOptions($workspace),
            persona_tone_options: EnumOptionData::fromCases(ReceptionPersonaTone::cases()),
            message_translation_failure_mode_options: EnumOptionData::fromCases(AutoMessageTranslationFailureMode::cases()),
            translation_provider_options: $this->buildTranslationProviderOptions($workspace),
            knowledge_base_options: $this->buildKnowledgeBaseOptions($workspace),
            mcp_tool_options: $this->buildMcpToolOptions($workspace),
            service_scenario_templates: array_map(
                static fn (array $template): ServiceScenarioTemplateData => ServiceScenarioTemplateData::fromArray($template),
                ServiceScenarioTemplates::all(),
            ),
        );
    }

    /**
     * Controller 入口：鉴权 + 限定本工作区后渲染详情页。
     */
    public function asController(Request $request, string $plan): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $planModel = ReceptionPlan::query()
            ->findOrFail($plan);

        return Inertia::render('reception/plans/Detail', $this->handle($workspace, $planModel)->toArray());
    }

    /**
     * 加载工作区可见 KB，供方案级 KB 多选项使用。
     *
     * @return list<PlanKnowledgeBaseOptionData>
     */
    private function buildKnowledgeBaseOptions(Workspace $workspace): array
    {
        return KnowledgeBase::query()
            ->orderBy('name')
            ->get()
            ->map(fn (KnowledgeBase $kb): PlanKnowledgeBaseOptionData => PlanKnowledgeBaseOptionData::fromModel($kb))
            ->all();
    }

    /**
     * 加载工作区可用 MCP 工具（server 已启用、tool 已启用且未下线），供方案级 MCP 工具多选项使用。
     *
     * @return list<PlanMcpToolOptionData>
     */
    private function buildMcpToolOptions(Workspace $workspace): array
    {
        return McpTool::query()
            ->with('server')
            ->whereHas('server', fn ($q) => $q
                ->where('is_active', true)
            )
            ->where('is_enabled', true)
            ->whereNull('removed_at')
            ->orderBy('name')
            ->get()
            ->map(fn (McpTool $tool): PlanMcpToolOptionData => PlanMcpToolOptionData::fromModel($tool))
            ->all();
    }

    /**
     * 加载工作区翻译供应商，供接待方案「信息翻译」的供应商 Select 选用。
     *
     * @return list<TranslationProviderOptionData>
     */
    private function buildTranslationProviderOptions(Workspace $workspace): array
    {
        return $workspace->translationProviders()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TranslationProvider $provider): TranslationProviderOptionData => TranslationProviderOptionData::fromModel($provider))
            ->all();
    }
}
