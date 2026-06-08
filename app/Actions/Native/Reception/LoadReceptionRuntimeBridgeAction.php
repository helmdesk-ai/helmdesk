<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\Plan\CollectPlanMcpServersAction;
use App\Data\Reception\Plan\ReceptionStrategyConfigData;
use App\Enums\AiModelPurpose;
use App\Enums\AiProviderProtocol;
use App\Enums\ConversationInboxStatus;
use App\Enums\ReceptionLanguage;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ReceptionPlanVersion;
use App\Services\AiRuntime\AiModelPool;
use App\Services\Localization\LocalePreference;
use App\Services\Reception\ChannelTeammateAvailability;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Native bridge 入口：为 Go 端接待 actor 加载当前会话的运行时配置。
 *
 * 返回结构按 `available` 字段分两种形态：
 * - available=true：附带 conversation_id / system_prompt / primary_model /
 *   model_candidates / primary_task_model / task_model_candidates / ai_unavailable_notice / quote_visitor_message_enabled；
 *   actor 用 model_candidates 按优先级构造 ChatModel，全部失败时发送 ai_unavailable_notice。
 * - available=false：附带 reason 让 actor 决定退出循环（taken_over / no_plan / no_model）。
 */
class LoadReceptionRuntimeBridgeAction
{
    use AsAction;

    public function __construct(
        private readonly ChannelTeammateAvailability $teammateAvailability,
        private readonly CollectPlanMcpServersAction $collectPlanMcpServers,
        private readonly AiModelPool $aiModelPool,
    ) {}

    /**
     * 按 conversation_id 查询会话并返回运行时配置。
     *
     * 直接按 conversation_id 锁定会话，确保 actor 异步调用稳定命中同一行。
     *
     * @return array<string, mixed>
     */
    public function handle(string $conversationId): array
    {
        $conversation = Conversation::query()->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $base = [
            'conversation_id' => (string) $conversation->id,
            'inbox_status' => $conversation->inbox_status->value,
        ];

        if ($conversation->inbox_status !== ConversationInboxStatus::AiHandling) {
            return $base + [
                'available' => false,
                'reason' => 'taken_over',
            ];
        }

        $versionId = $conversation->reception_plan_version_id;
        if (! filled($versionId)) {
            return $base + [
                'available' => false,
                'reason' => 'no_plan',
            ];
        }

        $version = ReceptionPlanVersion::query()->find($versionId);
        if ($version === null) {
            return $base + [
                'available' => false,
                'reason' => 'no_plan',
            ];
        }

        $compiled = $version->compiled_config;

        $modelCandidates = $this->resolveModelCandidates(AiModelPurpose::ReceptionChat);
        if ($modelCandidates === []) {
            return $base + [
                'available' => false,
                'reason' => 'no_model',
            ];
        }

        $taskModelCandidates = $this->resolveModelCandidates(AiModelPurpose::BackgroundTask);
        if ($taskModelCandidates === []) {
            return $base + [
                'available' => false,
                'reason' => 'no_model',
            ];
        }

        $systemPrompt = isset($compiled['reception_agent']['instruction']) && is_string($compiled['reception_agent']['instruction'])
            ? $compiled['reception_agent']['instruction']
            : '';

        $strategyConfig = $this->resolveStrategyConfig($version);

        $conversation->loadMissing('channel', 'contact');
        if ($conversation->channel !== null) {
            $language = $this->conversationLanguage($conversation);
            $locale = LocalePreference::normalizeLaravel($language->value);
            $status = $this->teammateAvailability->serviceStatus($conversation->channel, locale: $locale);
            $runtimeInstructions = [
                $systemPrompt,
                $this->visitorLanguageInstruction($language),
                $this->teammateAvailability->runtimeInstruction($status, $locale),
            ];
            $importantInstruction = $this->importantContactInstruction($conversation, $strategyConfig, $locale);
            if ($importantInstruction !== null) {
                $runtimeInstructions[] = $importantInstruction;
            }
            $systemPrompt = trim(implode("\n\n", $runtimeInstructions));
        }

        $serviceScenarios = $compiled['service_scenarios'];
        $knowledgeBases = $compiled['knowledge_bases'];
        $mcpServers = $this->collectMcpServers($compiled['mcp_tools']);

        return $base + [
            'available' => true,
            'plan_version_id' => (string) $version->id,
            'system_prompt' => $systemPrompt,
            'primary_model' => $modelCandidates[0],
            'model_candidates' => $modelCandidates,
            'primary_task_model' => $taskModelCandidates[0],
            'task_model_candidates' => $taskModelCandidates,
            'service_scenarios' => $serviceScenarios,
            'knowledge_bases' => $knowledgeBases,
            'mcp_servers' => $mcpServers,
            'ai_unavailable_notice' => $strategyConfig->ai_unavailable_notice,
            'quote_visitor_message_enabled' => $strategyConfig->quote_visitor_message_enabled,
        ];
    }

    /**
     * 生成重点客户接待提示词。
     */
    private function importantContactInstruction(Conversation $conversation, ReceptionStrategyConfigData $strategyConfig, string $locale): ?string
    {
        if (! $conversation->contact?->is_important) {
            return null;
        }

        $instructions = [];

        if ($strategyConfig->important_contact_ai_careful_reply_enabled) {
            $instructions[] = __('reception.important_contact_runtime.careful', [], $locale);
        }

        if ($strategyConfig->important_contact_ai_handoff_hint_enabled) {
            $instructions[] = __('reception.important_contact_runtime.handoff', [], $locale);
        }

        if ($instructions === []) {
            return null;
        }

        return implode("\n", [
            __('reception.important_contact_runtime.heading', [], $locale),
            __('reception.important_contact_runtime.marked', [], $locale),
            ...$instructions,
            __('reception.important_contact_runtime.silent', [], $locale),
        ]);
    }

    /**
     * 按 compiled_config.mcp_tools 中的 ID 列表反查当前可用的 MCP 服务，
     * 与 chat_stream 复用同一份 BridgeServer 形态，让任务 agent 端到端挂载工具。
     *
     * @param  list<array<string, mixed>>  $mcpToolSnapshots
     * @return list<array<string, mixed>>
     */
    private function collectMcpServers(array $mcpToolSnapshots): array
    {
        $toolIds = [];
        foreach ($mcpToolSnapshots as $snapshot) {
            $id = $snapshot['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $toolIds[] = $id;
            }
        }

        if ($toolIds === []) {
            return [];
        }

        return $this->collectPlanMcpServers->handle(array_values(array_unique($toolIds)));
    }

    /**
     * 从版本快照解析接待策略配置。
     */
    private function resolveStrategyConfig(ReceptionPlanVersion $version): ReceptionStrategyConfigData
    {
        $strategy = $version->snapshot_config['strategy_config'] ?? null;

        if (! is_array($strategy)) {
            throw new LogicException('Reception plan snapshot must contain strategy_config.');
        }

        return ReceptionStrategyConfigData::fromArray($strategy);
    }

    /**
     * 从全局用途池解析该用途下按主备顺序的模型候选列表（运行时逐个尝试、失败轮询）。
     *
     * @return list<array<string, mixed>>
     */
    private function resolveModelCandidates(AiModelPurpose $purpose): array
    {
        return $this->aiModelPool->modelsForPurpose($purpose)
            ->map(fn (AiModel $model): array => $this->encodeModel($model))
            ->all();
    }

    /**
     * 把 AiModel + AiProvider 投射成 Go BridgeProvider/BridgeModel 期望的形态。
     *
     * @return array<string, mixed>
     */
    private function encodeModel(AiModel $model): array
    {
        $provider = $model->provider;
        $protocol = $provider->protocol instanceof AiProviderProtocol
            ? $provider->protocol->value
            : (string) $provider->protocol;

        return [
            'provider' => [
                'slug' => (string) $provider->slug,
                'name' => (string) $provider->name,
                'protocol' => $protocol,
                'credentials' => $this->normalizeCredentials($provider->credentials ?? []),
                'credential_fields' => is_array($provider->credential_fields) ? $provider->credential_fields : [],
            ],
            'model' => [
                'model_id' => (string) $model->model_id,
                'name' => (string) $model->name,
                'type' => (string) $model->type,
                'is_active' => (bool) $model->is_active,
            ],
        ];
    }

    /**
     * 解析当前会话面向访客的接待语言。
     */
    private function conversationLanguage(Conversation $conversation): ReceptionLanguage
    {
        return ReceptionLanguage::from($conversation->visitor_locale);
    }

    /**
     * 生成 AI 回复语言的强制指令。
     */
    private function visitorLanguageInstruction(ReceptionLanguage $language): string
    {
        return 'Visitor-facing language: '.$language->promptName().'. Always reply to the visitor in '.$language->promptName().'.';
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    private function normalizeCredentials(array $credentials): array
    {
        $normalized = [];
        foreach ($credentials as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            $normalized[$key] = $trimmed;
        }

        return $normalized;
    }
}
