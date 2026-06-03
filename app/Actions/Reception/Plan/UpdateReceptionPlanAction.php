<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\Plan\AutoMessagesConfigData;
use App\Data\Reception\Plan\FormUpdateReceptionPlanData;
use App\Data\Reception\Plan\ReceptionMessageTranslationConfigData;
use App\Data\Reception\Plan\ReceptionStrategyConfigData;
use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\KnowledgeBase;
use App\Models\McpTool;
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
 * 更新接待方案配置（保存即发布）。
 * 编辑只动 reception_plans 草稿行；保存后自动编译并在配置实际变化时产出新的不可变 PlanVersion 快照，
 * 渠道自动跟随方案最新版，运营无需感知版本。
 * 表单一次提交基础信息 + 接待 / 任务模型 + 方案级知识库与 MCP 工具 + 全部服务场景，
 * service_scenarios 写入 capabilities 列；knowledge_base_ids / always_on_tools 同步整批更新。
 */
class UpdateReceptionPlanAction
{
    use AsAction;

    public function __construct(
        private readonly AiModelResolver $resolver,
        private readonly AutoMessageTemplateRenderer $autoMessageTemplateRenderer,
        private readonly EnsureReceptionPlanVersionAction $ensureReceptionPlanVersion,
    ) {}

    /**
     * 更新方案配置；所选模型必须仍在当前系统可用，名称在系统内保持唯一。
     */
    public function handle(SystemContext $systemContext, ReceptionPlan $plan, FormUpdateReceptionPlanData $data): void
    {
        $name = trim($data->name);
        $this->ensureNameIsAvailable($systemContext, $plan, $name);

        $this->resolver->assertActiveLlmModelOrFail($systemContext, $data->reception_ai_model_id, 'reception.messages.invalid_reception_model');
        $this->resolver->assertActiveLlmModelOrFail($systemContext, $data->task_ai_model_id, 'reception.messages.invalid_task_model');

        $knowledgeBaseIds = self::uniqueStringIds($data->knowledge_base_ids);
        $this->assertKnowledgeBaseIdsBelongToSystem($systemContext, $knowledgeBaseIds);

        $mcpToolIds = self::uniqueStringIds($data->mcp_tool_ids);
        $this->assertMcpToolIdsBelongToSystem($systemContext, $mcpToolIds);

        $serviceScenarios = $this->buildServiceScenarios($data->service_scenarios);
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

        $plan->update([
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
            'knowledge_base_ids' => $knowledgeBaseIds,
            'always_on_tools' => $mcpToolIds,
            'capabilities' => $serviceScenarios,
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => $autoMessagesConfig,
            'translation_config' => $translationConfig,
        ]);

        $this->ensureReceptionPlanVersion->handle($systemContext, $plan->refresh(), Auth::user());
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
     * 接收编辑表单后停留在当前方案详情页，方便用户继续配置。
     */
    public function asController(Request $request, string $plan): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::ReceptionPlansEdit);

        $planModel = ReceptionPlan::query()
            ->findOrFail($plan);

        $this->handle($systemContext, $planModel, FormUpdateReceptionPlanData::from($request));

        return redirect()->route('admin.manage.reception.plans.show', [
            'plan' => $planModel->id,
        ]);
    }

    /**
     * 同一系统内方案名称唯一（排除当前 plan 自身）。
     */
    private function ensureNameIsAvailable(SystemContext $systemContext, ReceptionPlan $plan, string $name): void
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
    private function assertKnowledgeBaseIdsBelongToSystem(SystemContext $systemContext, array $knowledgeBaseIds): void
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
    private function assertMcpToolIdsBelongToSystem(SystemContext $systemContext, array $mcpToolIds): void
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
