<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\Plan\ReceptionMessageTranslationConfigData;
use App\Enums\ReceptionPersonaTone;
use App\Exceptions\BusinessException;
use App\Models\KnowledgeBase;
use App\Models\McpTool;
use App\Models\ReceptionPlan;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 把 ReceptionPlan 草稿编译成 PlanVersion 快照所需的 snapshot / compiled 两份配置。
 *
 * - compiled_config.reception_agent.instruction 由 Persona + Global Instructions + 调度规则拼接
 *   其中"可用服务场景"清单（名称 + 描述）来自 capabilities 列表
 * - compiled_config.service_scenarios 是运行时服务场景列表，每项含 name / instructions；
 *   Go task agent 构造时一次性注入全量指令
 * - compiled_config.knowledge_base_ids 是方案级 KB 快照，Go 侧闭包捕获后按 plan 范围检索
 * - compiled_config.mcp_tools 是方案级 MCP 工具快照，Go 据此挂载工具
 * - compiled_config.reception_config / task_config 仅持默认模型与备用模型引用
 */
class CompileReceptionPlanAction
{
    use AsAction;

    public function __construct(
        private readonly AiModelResolver $resolver,
    ) {}

    /**
     * 输出 ['snapshot_config' => [...], 'compiled_config' => [...]]。
     *
     * @return array{snapshot_config: array<string, mixed>, compiled_config: array<string, mixed>}
     */
    public function handle(SystemContext $systemContext, ReceptionPlan $plan): array
    {
        $receptionConfig = $plan->reception_config ?? [];
        $taskConfig = $plan->task_config ?? [];
        $receptionDefaultModel = is_array($receptionConfig['default_model'] ?? null)
            ? $receptionConfig['default_model']
            : [];
        $taskDefaultModel = is_array($taskConfig['default_model'] ?? null)
            ? $taskConfig['default_model']
            : [];

        $this->resolver->assertActiveLlmModelOrFail($systemContext, $receptionDefaultModel['ai_model_id'] ?? null, 'reception.messages.invalid_reception_model');
        $this->resolver->assertActiveLlmModelOrFail($systemContext, $taskDefaultModel['ai_model_id'] ?? null, 'reception.messages.invalid_task_model');
        $receptionModelCandidates = $this->resolveModelCandidates($systemContext, $receptionConfig, $receptionDefaultModel, 'reception.messages.invalid_reception_model');
        $taskModelCandidates = $this->resolveModelCandidates($systemContext, $taskConfig, $taskDefaultModel, 'reception.messages.invalid_task_model');

        $personaConfig = $plan->persona_config ?? [];
        $capabilities = $plan->capabilities;
        $knowledgeBaseIds = $plan->knowledge_base_ids;
        $mcpToolIds = $plan->always_on_tools;
        $strategyConfig = $plan->strategy_config;
        $autoMessagesConfig = $plan->auto_messages_config;
        $translationConfig = ReceptionMessageTranslationConfigData::fromArray($plan->translation_config)->toConfigArray();

        $kbSnapshots = $this->loadKnowledgeBaseSnapshots($systemContext, $knowledgeBaseIds);
        $mcpToolSnapshots = $this->loadMcpToolSnapshots($systemContext, $mcpToolIds);

        $snapshotConfig = [
            'name' => $plan->name,
            'description' => $plan->description,
            'persona_config' => $personaConfig,
            'global_instructions' => $plan->global_instructions,
            'reception_config' => [
                'default_model' => $receptionDefaultModel,
                'model_candidates' => $receptionModelCandidates,
            ],
            'task_config' => [
                'default_model' => $taskDefaultModel,
                'model_candidates' => $taskModelCandidates,
            ],
            'capabilities' => $capabilities,
            'knowledge_base_ids' => $knowledgeBaseIds,
            'always_on_tools' => $mcpToolIds,
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => $autoMessagesConfig,
            'translation_config' => $translationConfig,
        ];

        $compiledConfig = [
            'reception_agent' => [
                'instruction' => $this->buildReceptionInstruction($personaConfig, $plan->global_instructions, $capabilities),
            ],
            'reception_config' => [
                'default_model' => $receptionDefaultModel,
                'model_candidates' => $receptionModelCandidates,
            ],
            'task_config' => [
                'default_model' => $taskDefaultModel,
                'model_candidates' => $taskModelCandidates,
            ],
            'service_scenarios' => $this->compileServiceScenarios($capabilities),
            'knowledge_bases' => $kbSnapshots,
            'mcp_tools' => $mcpToolSnapshots,
        ];

        return [
            'snapshot_config' => $snapshotConfig,
            'compiled_config' => $compiledConfig,
        ];
    }

    /**
     * 把 capabilities 列表编译成运行时服务场景列表（name + description + instructions）。
     * 数据由 UpdateReceptionPlanAction::buildServiceScenarios 在保存时归一化，
     * 这里直接按强类型访问，发现结构异常应在写入侧定位修复。
     *
     * @param  list<array{name: string, description: string, instructions: string}>  $capabilities
     * @return list<array{name: string, description: string, instructions: string}>
     */
    private function compileServiceScenarios(array $capabilities): array
    {
        return array_map(
            static fn (array $capability): array => [
                'name' => (string) $capability['name'],
                'description' => (string) $capability['description'],
                'instructions' => (string) $capability['instructions'],
            ],
            $capabilities,
        );
    }

    /**
     * 发布时重新校验候选模型，并在缺省候选列表时补齐默认模型。
     *
     * @param  array<string, mixed>  $modelConfig
     * @param  array<string, mixed>  $defaultModel
     * @return list<array{ai_model_id: string, priority: int}>
     */
    private function resolveModelCandidates(SystemContext $systemContext, array $modelConfig, array $defaultModel, string $messageKey): array
    {
        $primaryModelId = isset($defaultModel['ai_model_id']) && is_string($defaultModel['ai_model_id'])
            ? $defaultModel['ai_model_id']
            : null;

        if ($primaryModelId === null) {
            throw new BusinessException(__($messageKey));
        }

        $rawCandidates = isset($modelConfig['model_candidates']) && is_array($modelConfig['model_candidates'])
            ? $modelConfig['model_candidates']
            : [];

        if ($rawCandidates === []) {
            return [['ai_model_id' => $primaryModelId, 'priority' => 0]];
        }

        $seen = [];
        $candidates = [];
        foreach ($rawCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $modelId = isset($candidate['ai_model_id']) && is_string($candidate['ai_model_id'])
                ? $candidate['ai_model_id']
                : null;

            if ($modelId === null || isset($seen[$modelId])) {
                continue;
            }

            $this->resolver->assertActiveLlmModelOrFail($systemContext, $modelId, $messageKey);

            $seen[$modelId] = true;
            $candidates[] = [
                'ai_model_id' => $modelId,
                'priority' => $modelId === $primaryModelId
                    ? 0
                    : (isset($candidate['priority']) && is_numeric($candidate['priority'])
                    ? max(1, (int) $candidate['priority'])
                    : count($candidates) + 1),
            ];
        }

        if (! isset($seen[$primaryModelId])) {
            array_unshift($candidates, ['ai_model_id' => $primaryModelId, 'priority' => 0]);
        }

        usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_map(
            static fn (array $candidate, int $index): array => [
                'ai_model_id' => $candidate['ai_model_id'],
                'priority' => $index,
            ],
            $candidates,
            array_keys($candidates),
        );
    }

    /**
     * 按方案级知识库 ID 列表加载 KB 快照，验证所有 ID 仍可见。
     * description 透传给 Go 端，让 knowledge_search 工具描述里能体现每个 KB 的用途。
     *
     * @param  list<string>  $knowledgeBaseIds
     * @return list<array{id: string, name: string, description: string|null, category: string|null}>
     */
    private function loadKnowledgeBaseSnapshots(SystemContext $systemContext, array $knowledgeBaseIds): array
    {
        $ids = array_filter(array_unique($knowledgeBaseIds), static fn ($id) => is_string($id) && filled($id));
        if ($ids === []) {
            return [];
        }

        $snapshots = [];
        KnowledgeBase::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'description', 'category'])
            ->each(function (KnowledgeBase $kb) use (&$snapshots): void {
                $snapshots[(string) $kb->id] = [
                    'id' => (string) $kb->id,
                    'name' => $kb->name,
                    'description' => filled($kb->description) ? $kb->description : null,
                    'category' => $kb->category?->value,
                ];
            });

        foreach ($ids as $id) {
            if (! isset($snapshots[$id])) {
                throw new BusinessException(__('reception.messages.knowledge_base_invalid'));
            }
        }

        return array_values($snapshots);
    }

    /**
     * 按方案级 MCP 工具 ID 列表加载工具快照，验证所有 ID 仍可见。
     *
     * @param  list<string>  $mcpToolIds
     * @return list<array{id: string, name: string, description: string|null, server_id: string, server_slug: string, server_name: string}>
     */
    private function loadMcpToolSnapshots(SystemContext $systemContext, array $mcpToolIds): array
    {
        $ids = array_filter(array_unique($mcpToolIds), static fn ($id) => is_string($id) && filled($id));
        if ($ids === []) {
            return [];
        }

        $snapshots = [];
        McpTool::query()
            ->with('server:id,slug,name')
            ->whereIn('id', $ids)
            ->get(['id', 'mcp_server_id', 'name', 'description'])
            ->each(function (McpTool $tool) use (&$snapshots): void {
                $snapshots[(string) $tool->id] = [
                    'id' => (string) $tool->id,
                    'name' => $tool->name,
                    'description' => filled($tool->description) ? $tool->description : null,
                    'server_id' => (string) $tool->mcp_server_id,
                    'server_slug' => (string) ($tool->server?->slug ?? ''),
                    'server_name' => (string) ($tool->server?->name ?? ''),
                ];
            });

        foreach ($ids as $id) {
            if (! isset($snapshots[$id])) {
                throw new BusinessException(__('reception.messages.mcp_tool_invalid'));
            }
        }

        return array_values($snapshots);
    }

    /**
     * 把 Persona 头部 + 全局指引拼成接待 agent 的 system prompt。
     * 服务场景仅暴露名称与描述（轻量索引），完整指令由 task agent 在运行时加载。
     *
     * @param  array<string, mixed>  $personaConfig
     * @param  array<int, array<string, mixed>>  $capabilities
     */
    private function buildReceptionInstruction(array $personaConfig, ?string $globalInstructions, array $capabilities): string
    {
        $segments = [];

        $displayName = (string) $personaConfig['display_name'];
        $tone = ReceptionPersonaTone::from((string) $personaConfig['tone'])->label();

        $segments[] = implode("\n", [
            '[Persona]',
            "你的名字是 {$displayName}。",
            "保持 {$tone} 的语气与访客交流。",
        ]);

        if (filled($globalInstructions)) {
            $segments[] = "[全局指引]\n".$globalInstructions;
        }

        $segments[] = $this->buildDispatchInstruction($capabilities);

        return $segments === [] ? '' : implode("\n\n", $segments);
    }

    /**
     * 生成调度规则段落。
     * 服务场景以「名称 + 描述」形式列入接待 agent 提示词，作为它判断哪些问题该派发的参考；
     * 派发时不需要指定场景，dispatch_task 接收 question，任务 agent 自带全部场景指令完成匹配。
     *
     * @param  array<int, array<string, mixed>>  $capabilities
     */
    private function buildDispatchInstruction(array $capabilities): string
    {
        $lines = [
            '[调度规则]',
            '你的职责是和访客对话、判断意图、派发后台任务，并在必要时转人工。',
            '简单问候、闲聊或无需后台能力的问题，直接给出最终回复。',
            '需要后台查询或业务处理的问题，调用 dispatch_task：',
            '  - question：写完整问题（访客需求、已知参数、约束条件、期望输出）。任务 agent 不读对话历史，所需信息必须全部由 question 提供。',
            '任务结果异步回流后，由你在最终回复里转述给访客。',
            '访客补充同一任务信息时，先 cancel_task 原任务（task_id 在你之前的 dispatch_task 返回里），再带完整 question 重新 dispatch_task。',
            '访客催促时调用 query_task 查询状态，不要重复派发。',
            '工具失败、置信度低或访客主动要求人工时，调用 handoff_to_human；该工具会直接把 notice 送达访客，并作为本轮面向访客的最终动作。',
        ];

        if ($capabilities === []) {
            $lines[] = '当前没有配置可派发的服务场景；除转人工外，请直接给出最终回复处理访客消息。';

            return implode("\n", $lines);
        }

        $lines[] = '任务 agent 当前能处理的服务场景（仅供你判断是否派发；调用 dispatch_task 时无需指定场景）：';
        foreach ($capabilities as $capability) {
            $name = isset($capability['name']) && filled($capability['name'])
                ? (string) $capability['name']
                : '';
            if ($name === '') {
                continue;
            }

            $description = isset($capability['description']) && filled($capability['description'])
                ? (string) $capability['description']
                : '';
            $lines[] = "- {$name}".($description !== '' ? "（{$description}）" : '');
        }

        return implode("\n", $lines);
    }
}
