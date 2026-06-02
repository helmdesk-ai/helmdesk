<?php

namespace App\Actions\Conversation;

use App\Data\Conversation\GeneratedConversationTagData;
use App\Enums\AiModelType;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Enums\TagScope;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tag;
use App\Services\Conversation\GoConversationSummaryBridge;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 受控词表下，为单次会话生成 AI 标签并落库（吃会话摘要 + 完整消息上下文）。
 * AI 只负责增补/刷新标签；删除、抑制等人工边界由 ApplyConversationTagSuggestionsAction 守住。
 */
class GenerateConversationTagsAction
{
    use AsAction;

    private const CONFIDENCE_THRESHOLD = 0.5;

    private const MAX_CONTEXT_CHARACTERS = 150_000;

    /**
     * 注入 Go 标签桥接和实时通知服务。
     */
    public function __construct(
        private readonly GoConversationSummaryBridge $bridge,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
    ) {}

    /**
     * 生成并应用会话标签；finalize 表示会话关闭后的定稿请求。
     */
    public function handle(Conversation $conversation, bool $finalize = false): void
    {
        $conversation->loadMissing(['receptionPlanVersion']);
        Log::info('[conversation-tags] start', [
            'conversation_id' => $conversation->id,
            'finalize' => $finalize,
        ]);

        $vocabulary = $this->resolveVocabulary($conversation);
        if ($vocabulary === []) {
            Log::info('[conversation-tags] skip: empty conversation-scope vocabulary', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $messages = $this->collectMessages($conversation);
        if ($messages === [] && blank($conversation->summary)) {
            Log::info('[conversation-tags] skip: no summary and no messages', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $candidates = array_map(static fn (Tag $tag): array => [
            'tag_id' => (string) $tag->id,
            'name' => $tag->name,
            'description' => $tag->description,
            'group' => $tag->tagGroup?->name,
        ], array_values($vocabulary));

        $suggestions = $this->generateWithCandidates($conversation, $candidates, $messages);
        Log::info('[conversation-tags] model returned suggestions', [
            'conversation_id' => $conversation->id,
            'vocabulary_count' => count($vocabulary),
            'message_count' => count($messages),
            'suggestions' => array_map(static fn (GeneratedConversationTagData $s): array => [
                'tag_id' => $s->tag_id,
                'name' => $s->name,
                'confidence' => $s->confidence,
            ], $suggestions),
        ]);

        $applied = $this->mapSuggestionsToTags($suggestions, $vocabulary, $conversation);
        Log::info('[conversation-tags] applied after threshold/mapping', [
            'conversation_id' => $conversation->id,
            'threshold' => self::CONFIDENCE_THRESHOLD,
            'applied_count' => count($applied),
        ]);

        ApplyConversationTagSuggestionsAction::run($conversation, $applied, $finalize);

        if ($applied !== [] || $finalize) {
            $this->realtimeNotifier->conversationChanged($conversation->refresh(), 'conversation_tags_updated');
        }
    }

    /**
     * 取当前系统会话维度的受控词表（标签 ID → Tag）。
     *
     * @return array<string, Tag>
     */
    private function resolveVocabulary(Conversation $conversation): array
    {
        $tags = Tag::query()
            ->whereHas('tagGroup', fn (Builder $query) => $query->where('scope', TagScope::Conversation->value))
            ->with('tagGroup')
            ->get();

        $map = [];
        foreach ($tags as $tag) {
            $map[(string) $tag->id] = $tag;
        }

        return $map;
    }

    /**
     * 把 AI 返回的标签 ID 映射回 Tag，做阈值过滤，组装成可应用的建议。
     *
     * @param  list<GeneratedConversationTagData>  $suggestions
     * @param  array<string, Tag>  $vocabulary
     * @return list<array{tag_id: string, confidence: float, reason: string|null, based_on_seq_no: int|null}>
     */
    private function mapSuggestionsToTags(array $suggestions, array $vocabulary, Conversation $conversation): array
    {
        $basedOnSeqNo = (int) $conversation->summary_last_message_seq_no ?: null;
        $applied = [];

        foreach ($suggestions as $suggestion) {
            if ($suggestion->confidence < self::CONFIDENCE_THRESHOLD) {
                continue;
            }

            if ($suggestion->tag_id === null) {
                continue;
            }

            $tag = $vocabulary[$suggestion->tag_id] ?? null;
            if ($tag === null) {
                continue;
            }

            $applied[] = [
                'tag_id' => $tag->id,
                'confidence' => $suggestion->confidence,
                'reason' => $suggestion->reason,
                'based_on_seq_no' => $basedOnSeqNo,
            ];
        }

        return $applied;
    }

    /**
     * 按接待方案候选模型顺序调用打标签桥接。
     *
     * @param  list<array{tag_id: string, name: string, description: ?string, group: ?string}>  $candidates
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<GeneratedConversationTagData>
     */
    private function generateWithCandidates(Conversation $conversation, array $candidates, array $messages): array
    {
        $lastError = null;
        $models = $this->resolveCandidateModels($conversation);
        Log::info('[conversation-tags] candidate models resolved', [
            'conversation_id' => $conversation->id,
            'model_count' => count($models),
        ]);

        foreach ($models as $model) {
            try {
                return $this->bridge->generateConversationTags(
                    provider: $model->provider,
                    model: $model,
                    locale: $conversation->visitor_locale,
                    candidates: $candidates,
                    summary: $conversation->summary,
                    messages: $messages,
                );
            } catch (Throwable $exception) {
                $lastError = $exception;
                Log::warning('会话标签生成候选模型失败', [
                    'conversation_id' => $conversation->id,
                    'ai_model_id' => $model->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        return [];
    }

    /**
     * 读取完整文本消息作为打标签上下文，并按 150K 字符预算截断。
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
     * 解析打标签使用的模型候选项：轻量任务优先用任务智能体（task_config）配置的模型，任务槽未配置时回退到接待模型。
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
}
