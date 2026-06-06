<?php

namespace App\Actions\Contact;

use App\Data\Contact\GeneratedContactAiSummaryData;
use App\Enums\AiModelType;
use App\Models\AiModel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\Contact\ContactAiContext;
use App\Services\Conversation\GoConversationSummaryBridge;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 基于联系人历史会话摘要滚动生成联系人级 AI 摘要。
 */
class GenerateContactAiSummaryAction
{
    use AsAction;

    private const MAX_CONVERSATIONS = 12;

    /**
     * 注入 Go 摘要桥接和实时通知服务。
     */
    public function __construct(
        private readonly GoConversationSummaryBridge $bridge,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
    ) {}

    /**
     * 更新 contacts.ai_context.summary，并通知收件箱刷新。
     */
    public function handle(Contact $contact): ?array
    {
        $digests = $this->conversationDigests($contact);
        if ($digests === []) {
            return null;
        }

        $locale = $this->resolveSourceLocale($contact, $digests);
        $result = $this->generateWithCandidates($contact, $locale, $digests);
        $summary = $this->normalizeSummary($contact->ai_context, $result, $locale);

        $contact->forceFill([
            'ai_context' => ContactAiContext::normalize($summary),
        ])->save();

        $latestConversation = $this->latestConversation($contact);
        if ($latestConversation instanceof Conversation) {
            $this->realtimeNotifier->conversationChanged(
                $latestConversation,
                'contact_ai_summary_updated',
                meta: ['contact_id' => (string) $contact->id],
            );
        }

        return $contact->ai_context;
    }

    /**
     * 汇总最近若干次会话的摘要和事实。
     *
     * @return list<array<string, mixed>>
     */
    private function conversationDigests(Contact $contact): array
    {
        return Conversation::query()
            ->where('contact_id', $contact->id)
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('summary')
                    ->orWhereNotNull('ai_context');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_CONVERSATIONS)
            ->get(['id', 'summary', 'summary_locale', 'ai_context', 'visitor_locale', 'created_at', 'closed_at'])
            ->reverse()
            ->values()
            ->map(function (Conversation $conversation): array {
                $facts = is_array($conversation->ai_context) && is_array($conversation->ai_context['summary_facts'] ?? null)
                    ? $conversation->ai_context['summary_facts']
                    : [];

                return [
                    'id' => (string) $conversation->id,
                    'summary' => filled($conversation->summary) ? (string) $conversation->summary : null,
                    'summary_locale' => filled($conversation->summary_locale) ? (string) $conversation->summary_locale : $conversation->visitor_locale,
                    'visitor_locale' => $conversation->visitor_locale,
                    'topics' => $this->stringList($facts['topics'] ?? []),
                    'open_issues' => $this->stringList($facts['open_issues'] ?? []),
                    'preferences' => $this->stringList($facts['preferences'] ?? []),
                    'occurred_at' => $conversation->closed_at?->toIso8601String() ?? $conversation->created_at?->toIso8601String(),
                ];
            })
            ->filter(fn (array $digest): bool => filled($digest['summary']) || $digest['topics'] !== [] || $digest['open_issues'] !== [] || $digest['preferences'] !== [])
            ->values()
            ->all();
    }

    /**
     * 按最新会话语言作为联系人摘要源语言。
     *
     * @param  list<array<string, mixed>>  $digests
     */
    private function resolveSourceLocale(Contact $contact, array $digests): string
    {
        $latest = $digests[array_key_last($digests)] ?? [];
        $locale = $latest['visitor_locale'] ?? $contact->locale;

        return is_string($locale) && filled($locale) ? $locale : 'zh-CN';
    }

    /**
     * 按最近会话接待模型或系统首个可用模型生成联系人摘要。
     *
     * @param  list<array<string, mixed>>  $digests
     */
    private function generateWithCandidates(Contact $contact, string $locale, array $digests): GeneratedContactAiSummaryData
    {
        $lastError = null;
        $context = $contact->ai_context;
        $existingSummary = is_array($context) && is_array($context['summary'] ?? null)
            ? $this->stripExistingSummaryForPrompt($context['summary'])
            : null;

        foreach ($this->resolveCandidateModels($contact) as $model) {
            try {
                return $this->bridge->generateContact(
                    provider: $model->provider,
                    model: $model,
                    locale: $locale,
                    conversationDigests: $digests,
                    existingSummary: $existingSummary,
                );
            } catch (Throwable $exception) {
                $lastError = $exception;
                Log::warning('联系人 AI 摘要生成候选模型失败', [
                    'contact_id' => $contact->id,
                    'ai_model_id' => $model->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new \RuntimeException('No available contact summary model.');
    }

    /**
     * 从最近会话版本或系统模型中解析候选模型。
     *
     * @return list<AiModel>
     */
    private function resolveCandidateModels(Contact $contact): array
    {
        $latest = Conversation::query()
            ->with('receptionPlanVersion')
            ->where('contact_id', $contact->id)
            ->whereNotNull('reception_plan_version_id')
            ->orderByDesc('created_at')
            ->first();

        $modelIds = [];
        $compiled = $latest?->receptionPlanVersion?->compiled_config;
        $receptionConfig = is_array($compiled) && is_array($compiled['reception_config'] ?? null)
            ? $compiled['reception_config']
            : [];

        foreach (($receptionConfig['model_candidates'] ?? []) as $candidate) {
            $modelId = is_array($candidate) && is_string($candidate['ai_model_id'] ?? null)
                ? $candidate['ai_model_id']
                : null;
            if ($modelId !== null) {
                $modelIds[$modelId] = true;
            }
        }

        $defaultModelId = $receptionConfig['default_model']['ai_model_id'] ?? null;
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

        if ($ordered !== []) {
            return $ordered;
        }

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

    /**
     * 取出送进 Go 模型 prompt 的 existingSummary 源语言字段。
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function stripExistingSummaryForPrompt(array $summary): array
    {
        return [
            'profile_summary' => $summary['profile_summary'] ?? null,
            'open_issues' => $this->stringList($summary['open_issues'] ?? []),
            'preferences' => $this->stringList($summary['preferences'] ?? []),
            'recent_topics' => $this->stringList($summary['recent_topics'] ?? []),
            'source_locale' => $summary['source_locale'] ?? null,
        ];
    }

    /**
     * 组装新的联系人 AI 上下文。
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function normalizeSummary(?array $context, GeneratedContactAiSummaryData $result, string $locale): array
    {
        $context ??= [];
        $context['summary'] = [
            'profile_summary' => $result->profile_summary,
            'open_issues' => $result->open_issues,
            'preferences' => $result->preferences,
            'recent_topics' => $result->recent_topics,
            'source_locale' => $locale,
            'translations' => [],
            'updated_at' => now()->toIso8601String(),
        ];

        return $context;
    }

    /**
     * 查询该联系人最近一次会话，用于广播刷新收件箱。
     */
    private function latestConversation(Contact $contact): ?Conversation
    {
        return Conversation::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * 将任意数组清理为非空字符串列表。
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
