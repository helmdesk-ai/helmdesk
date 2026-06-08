<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\Plan\AutoMessagesConfigData;
use App\Data\Reception\Plan\FormUpdateReceptionPlanData;
use App\Data\Reception\Plan\ReceptionMessageTranslationConfigData;
use App\Data\Reception\Plan\ReceptionStrategyConfigData;
use App\Enums\UserPermission;
use App\Models\KnowledgeBase;
use App\Models\McpTool;
use App\Models\ReceptionPlan;
use App\Services\Reception\AutoMessageTemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新接待方案配置（保存即发布）。
 * 编辑只动 reception_plans 草稿行；保存后自动编译并在配置实际变化时产出新的不可变 PlanVersion 快照，
 * 渠道自动跟随方案最新版，运营无需感知版本。
 * 表单一次提交基础信息 + 方案级知识库与 MCP 工具 + 全部服务场景，
 * service_scenarios 写入 capabilities 列；knowledge_base_ids / always_on_tools 同步整批更新。
 * 模型不再由方案选择，运行时按用途从全局池取用。
 */
class UpdateReceptionPlanAction
{
    use AsAction;

    public function __construct(
        private readonly AutoMessageTemplateRenderer $autoMessageTemplateRenderer,
        private readonly EnsureReceptionPlanVersionAction $ensureReceptionPlanVersion,
    ) {}

    /**
     * 更新方案配置；名称在系统内保持唯一。
     */
    public function handle(ReceptionPlan $plan, FormUpdateReceptionPlanData $data): void
    {
        $name = trim($data->name);
        $this->ensureNameIsAvailable($plan, $name);

        $knowledgeBaseIds = self::uniqueStringIds($data->knowledge_base_ids);
        $this->assertKnowledgeBaseIdsBelongToSystem($knowledgeBaseIds);

        $mcpToolIds = self::uniqueStringIds($data->mcp_tool_ids);
        $this->assertMcpToolIdsBelongToSystem($mcpToolIds);

        $serviceScenarios = $this->buildServiceScenarios($data->service_scenarios);
        $autoMessagesConfig = $this->buildAutoMessagesConfig($data->auto_messages_config);
        $translationConfig = ReceptionMessageTranslationConfigData::fromArray($data->translation_config)->toConfigArray();
        $strategyConfig = ReceptionStrategyConfigData::fromArray($data->strategy_config)->toConfigArray();

        $plan->update([
            'name' => $name,
            'description' => filled($data->description) ? $data->description : null,
            'persona_config' => [
                'display_name' => $data->persona_display_name,
                'tone' => $data->persona_tone,
            ],
            'global_instructions' => filled($data->global_instructions) ? $data->global_instructions : null,
            'reception_config' => [],
            'task_config' => [],
            'knowledge_base_ids' => $knowledgeBaseIds,
            'always_on_tools' => $mcpToolIds,
            'capabilities' => $serviceScenarios,
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => $autoMessagesConfig,
            'translation_config' => $translationConfig,
        ]);

        $this->ensureReceptionPlanVersion->handle($plan->refresh(), Auth::user());
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
     * 接收编辑表单后停留在当前方案详情页，方便用户继续配置。
     */
    public function asController(Request $request, string $plan): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::ReceptionPlansEdit);

        $planModel = ReceptionPlan::query()
            ->findOrFail($plan);

        $this->handle($planModel, FormUpdateReceptionPlanData::from($request));

        return redirect()->route('admin.manage.reception.plans.show', [
            'plan' => $planModel->id,
        ]);
    }

    /**
     * 同一系统内方案名称唯一（排除当前 plan 自身）。
     */
    private function ensureNameIsAvailable(ReceptionPlan $plan, string $name): void
    {
        $exists = ReceptionPlan::query()
            ->where('name', $name)
            ->whereKeyNot($plan->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('reception.messages.plan_name_exists'),
            ]);
        }
    }

    /**
     * 将提交的服务场景数组规整为可写入 capabilities JSON 列的形态。
     * 每个场景只保留 name / description / instructions 三个字段。
     *
     * @param  list<array<string, mixed>>  $rawScenarios
     * @return list<array<string, mixed>>
     */
    private function buildServiceScenarios(array $rawScenarios): array
    {
        $normalized = [];

        foreach ($rawScenarios as $raw) {
            $normalized[] = [
                'name' => isset($raw['name']) && is_string($raw['name']) ? trim($raw['name']) : '',
                'description' => isset($raw['description']) && is_string($raw['description']) ? trim($raw['description']) : '',
                'instructions' => isset($raw['instructions']) && is_string($raw['instructions']) ? $raw['instructions'] : '',
            ];
        }

        return $normalized;
    }

    /**
     * 校验方案级知识库 ID 必须存在于当前系统。
     *
     * @param  list<string>  $knowledgeBaseIds
     */
    private function assertKnowledgeBaseIdsBelongToSystem(array $knowledgeBaseIds): void
    {
        $unique = array_values(array_unique($knowledgeBaseIds));
        if ($unique === []) {
            return;
        }

        $validCount = KnowledgeBase::query()
            ->whereIn('id', $unique)
            ->count();

        if ($validCount !== count($unique)) {
            throw ValidationException::withMessages([
                'knowledge_base_ids' => __('reception.messages.knowledge_base_invalid'),
            ]);
        }
    }

    /**
     * 校验方案级 MCP 工具 ID 必须来自可用工具列表。
     *
     * @param  list<string>  $mcpToolIds
     */
    private function assertMcpToolIdsBelongToSystem(array $mcpToolIds): void
    {
        $unique = array_values(array_unique($mcpToolIds));
        if ($unique === []) {
            return;
        }

        $validCount = McpTool::query()
            ->whereIn('id', $unique)
            ->whereNull('removed_at')
            ->whereHas('server', fn ($q) => $q
                ->whereNotNull('endpoint_url')
                ->where('endpoint_url', '!=', '')
            )
            ->count();

        if ($validCount !== count($unique)) {
            throw ValidationException::withMessages([
                'mcp_tool_ids' => __('reception.messages.mcp_tool_invalid'),
            ]);
        }
    }

    /**
     * 把任意可迭代 ID 列表规整为去重后的非空字符串列表。
     *
     * @param  array<int, mixed>  $raw
     * @return list<string>
     */
    private static function uniqueStringIds(array $raw): array
    {
        $unique = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $unique[$trimmed] = true;
        }

        return array_keys($unique);
    }
}
