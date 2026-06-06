<?php

namespace App\Actions\Conversation;

use App\Data\Conversation\GeneratedConversationSummaryData;
use App\Enums\AiModelType;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Jobs\Contact\GenerateContactAiSummaryJob;
use App\Jobs\Conversation\GenerateConversationTagsJob;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Conversation\GoConversationSummaryBridge;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 为单次会话生成并保存访客语言摘要。
 */
class GenerateConversationSummaryAction
{
    use AsAction;

    private const MAX_CONTEXT_CHARACTERS = 150_000;

    /**
     * 注入 Go 摘要桥接和实时通知服务。
     */
    public function __construct(
        private readonly GoConversationSummaryBridge $bridge,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
    ) {}

    /**
     * 根据完整会话文本生成摘要，并同步更新联系人 AI 摘要。
     */
    public function handle(Conversation $conversation, bool $force = false): ?string
    {
        $conversation->loadMissing(['receptionPlanVersion', 'contact']);
        $latestSeqNo = $this->latestTextSeqNo($conversation);

        if ($latestSeqNo === null) {
            return null;
        }

        if (! $force && filled($conversation->summary) && (int) $conversation->summary_last_message_seq_no >= $latestSeqNo) {
            return $conversation->summary;
        }

        $messages = $this->collectMessages($conversation);
        if ($messages === []) {
            return $conversation->summary;
        }

        $result = $this->generateWithCandidates($conversation, $messages);
        $summary = $this->normalizeSummary($result->summary);
        if ($summary === null) {
            return $conversation->summary;
        }

        $aiContext = $this->mergeConversationSummaryContext($conversation->ai_context, $result);

        $conversation->forceFill([
            'summary' => $summary,
            'summary_locale' => $conversation->visitor_locale,
            'summary_translations' => null,
            'summary_last_message_seq_no' => $latestSeqNo,
            'summary_generated_at' => now(),
            'ai_context' => $aiContext,
        ])->save();

        $this->realtimeNotifier->conversationChanged($conversation->refresh(), 'conversation_summary_updated');

        // 摘要完成后再基于同一段完整上下文打标签；AI 标签只增补/刷新，不负责删除。
        Log::info('[conversation-tags] dispatching tags job after summary', [
            'conversation_id' => $conversation->id,
        ]);
        GenerateConversationTagsJob::dispatch((string) $conversation->id)->afterCommit();

        if ($conversation->contact_id !== null) {
            GenerateContactAiSummaryJob::dispatch((string) $conversation->contact_id)->afterCommit();
        }

        return $summary;
    }

    /**
     * 获取当前会话最后一条可进入摘要的文本消息 seq_no。
     */
    private function latestTextSeqNo(Conversation $conversation): ?int
    {
        $seqNo = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
            ->where('kind', MessageKind::Text)
            ->whereNotNull('content')
            ->whereNull('recalled_at')
            ->max('seq_no');

        return $seqNo !== null ? (int) $seqNo : null;
    }

    /**
     * 读取完整文本消息，并按 150K 字符预算截断。
     *
     * @return list<array{role: string, content: string}>
     */
    private function collectMessages(Conversation $conversation): array
    {
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
            ->where('kind', MessageKind::Text)
            ->whereNotNull('content')
            ->whereNull('recalled_at')
            ->orderBy('seq_no')
            ->get(['role', 'content', 'seq_no']);

        $remaining = self::MAX_CONTEXT_CHARACTERS;
        $collected = [];

        foreach ($messages as $message) {
            $content = trim((string) $message->content);
            if ($content === '') {
                continue;
            }

            $length = Str::length($content);
            if ($length > $remaining) {
                $content = Str::substr($content, 0, $remaining);
                $length = Str::length($content);
            }

            $collected[] = [
                'role' => $message->role->value,
                'content' => $content,
            ];

            $remaining -= $length;
            if ($remaining <= 0) {
                break;
            }
        }

        return $collected;
    }

    /**
     * 按接待方案候选模型顺序生成摘要。
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function generateWithCandidates(Conversation $conversation, array $messages): GeneratedConversationSummaryData
    {
        $lastError = null;

        foreach ($this->resolveCandidateModels($conversation) as $model) {
            try {
                return $this->bridge->generateConversation(
                    provider: $model->provider,
                    model: $model,
                    locale: $conversation->visitor_locale,
                    messages: $messages,
                    existingSummary: $conversation->summary,
                );
            } catch (Throwable $exception) {
                $lastError = $exception;
                Log::warning('会话摘要生成候选模型失败', [
                    'conversation_id' => $conversation->id,
                    'ai_model_id' => $model->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new \RuntimeException('No available conversation summary model.');
    }

    /**
     * 解析摘要使用的模型候选项：轻量任务优先用任务智能体（task_config）配置的模型，任务槽未配置时回退到接待模型。
     *
     * @return list<AiModel>
     */
    private function resolveCandidateModels(Conversation $conversation): array
    {
        $modelIds = [];
        $config = $conversation->receptionPlanVersion?->resolveTaskAgentConfig() ?? [];
        $candidates = is_array($config['model_candidates'] ?? null)
            ? $config['model_candidates']
            : [];

        foreach ($candidates as $candidate) {
            $modelId = is_array($candidate) && is_string($candidate['ai_model_id'] ?? null)
                ? $candidate['ai_model_id']
                : null;
            if ($modelId !== null) {
                $modelIds[$modelId] = true;
            }
        }

        $defaultModelId = $config['default_model']['ai_model_id'] ?? null;
        if (is_string($defaultModelId)) {
            $modelIds[$defaultModelId] = true;
        }

        $models = AiModel::query()
            ->with('provider')
            ->whereIn('id', array_keys($modelIds))
            ->where('type', AiModelType::Llm->value)
            ->where('is_active', true)
            ->whereHas('provider', function (Builder $query): void {
                $query
                    ->where('is_active', true);
            })
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach (array_keys($modelIds) as $modelId) {
            $model = $models->get($modelId);
            if ($model instanceof AiModel) {
                $ordered[] = $model;
            }
        }

        if ($ordered === []) {
            $fallback = AiModel::query()
                ->with('provider')
                ->where('type', AiModelType::Llm->value)
                ->where('is_active', true)
                ->whereHas('provider', function (Builder $query): void {
                    $query
                        ->where('is_active', true);
                })
                ->orderBy('sort_order')
                ->first();

            return $fallback instanceof AiModel ? [$fallback] : [];
        }

        return $ordered;
    }

    /**
     * 清理摘要文本。
     */
    private function normalizeSummary(string $summary): ?string
    {
        if (! mb_check_encoding($summary, 'UTF-8')) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($summary));

        return is_string($normalized) && $normalized !== '' ? Str::limit($normalized, 1200, '') : null;
    }

    /**
     * 把摘要事实写入 conversation.ai_context，供联系人级摘要滚动吸收。
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function mergeConversationSummaryContext(?array $context, GeneratedConversationSummaryData $result): array
    {
        $context ??= [];
        $context['summary_facts'] = [
            'topics' => $result->topics,
            'open_issues' => $result->open_issues,
            'preferences' => $result->preferences,
            'updated_at' => now()->toIso8601String(),
        ];

        return $context;
    }
}
