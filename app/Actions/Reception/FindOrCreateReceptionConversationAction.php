<?php

namespace App\Actions\Reception;

use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\ConversationEntryMode;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Reception\ReceptionRoutingMode;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\Reception\ChannelActivePlanVersionResolver;
use App\Services\Reception\ChannelAiAvailability;
use App\Services\Reception\ChannelTeammateAvailability;
use App\Services\Reception\ReceptionPlanStrategyResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

/**
 * 查找或创建访客接待会话，封装与具体渠道无关的会话生命周期决策：
 * 初始收件箱状态、AI 欢迎语、无人接待/客服无响应时的 AI 接管。
 *
 * 网站、Telegram 等各渠道的上下文解析 Action 各自完成「渠道查找 + 身份解析」后调用本 Action，
 * 仅需把渠道默认访客语言以字符串传入，避免本 Action 依赖某一渠道的 settings 结构。
 */
class FindOrCreateReceptionConversationAction
{
    use AsAction;

    /** AI 不可用后的冷却时长（秒），避免无人在线场景下循环切 AI → teammate_pending。 */
    private const AI_COOLDOWN_SECONDS = 300;

    /**
     * @var array<string, bool>
     */
    private array $teammateStatusCache = [];

    /**
     * 本次解析内缓存的渠道当前生效版本 ID，避免重复解析。
     *
     * @var array<string, ?string>
     */
    private array $channelPlanVersionIdCache = [];

    /**
     * 注入 AI 可用性、人工服务可用性、接待策略解析、AI 欢迎语派发与渠道当前生效版本解析服务。
     */
    public function __construct(
        private readonly ChannelAiAvailability $aiAvailability,
        private readonly ChannelTeammateAvailability $teammateAvailability,
        private readonly ReceptionPlanStrategyResolver $strategyResolver,
        private readonly DispatchConversationAutoMessageAction $dispatchConversationAutoMessageAction,
        private readonly ChannelActivePlanVersionResolver $activePlanVersionResolver,
    ) {}

    /**
     * 查找访客当前开放会话，不存在时创建新会话并发出创建事件与 AI 欢迎语。
     *
     * $defaultVisitorLocale 为新会话兜底访客语言（取值来自各渠道设置），用于初始化 visitor_locale。
     *
     * @return array{0: Conversation, 1: bool} [conversation, created]
     */
    public function handle(
        Channel $channel,
        Contact $contact,
        ConversationEntryMode $entryMode,
        string $defaultVisitorLocale,
    ): array {
        $this->teammateStatusCache = [];
        $this->channelPlanVersionIdCache = [];

        $existing = $this->openConversationQuery($channel, $contact)->first();
        if ($existing) {
            $existing = $this->applyUnassignedAiTakeoverIfNeeded($channel, $existing);
            $existing = $this->applyTeammateNoResponseAiTakeoverIfNeeded($channel, $existing);

            return [$existing, false];
        }

        if ($channel->trashed()) {
            throw new GoneHttpException('channel is paused');
        }

        try {
            $createdConversationResult = DB::transaction(function () use ($channel, $contact, $entryMode, $defaultVisitorLocale): array {
                $conversation = Conversation::query()->create([
                    'workspace_id' => $channel->workspace_id,
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'reception_plan_version_id' => $this->currentPlanVersionId($channel),
                    'visitor_locale' => $defaultVisitorLocale,
                    'entry_mode' => $entryMode,
                    'source' => ConversationSource::Channel,
                    'status' => ConversationStatus::Open,
                    'inbox_status' => $this->resolveInitialInboxStatus($channel, $contact),
                ]);

                ConversationEvent::query()->create([
                    'workspace_id' => $channel->workspace_id,
                    'conversation_id' => $conversation->id,
                    'type' => ConversationEventType::Created,
                    'payload' => ['source' => 'reception'],
                    'created_at' => now(),
                ]);

                return [$conversation, true];
            });

            [$conversation] = $createdConversationResult;
            $this->dispatchAiWelcomeAfterAiHandlingStatusChange($conversation, notify: false);

            return [$conversation->fresh(), true];
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->openConversationQuery($channel, $contact)->first();
            if ($existing === null) {
                throw $e;
            }

            Log::debug('访客会话创建遇到并发唯一约束。', [
                'workspace_id' => (string) $channel->workspace_id,
                'channel_id' => (string) $channel->id,
                'contact_id' => (string) $contact->id,
                'conversation_id' => (string) $existing->id,
            ]);

            return [$existing, false];
        }
    }

    /**
     * 在人工待接会话无人可接或等待超时后将会话交给 AI 接待。
     *
     * 接管时同步渠道当前部署版本，确保 AI 使用最新接待方案。
     */
    private function applyUnassignedAiTakeoverIfNeeded(Channel $channel, Conversation $conversation): Conversation
    {
        if (
            $conversation->assigned_user_id !== null
            || $conversation->inbox_status !== ConversationInboxStatus::TeammatePending
            || ! $this->aiAvailability->canUseAi($channel)
            || $this->isAiCooldownActive($conversation)
        ) {
            return $conversation;
        }

        $queuedAt = $conversation->last_message_at ?? $conversation->created_at;
        $teammateUnavailable = ! $this->isTeammateAvailable($channel);
        $takeoverDue = $this->aiAvailability->unassignedAiTakeoverIsDue($channel, $queuedAt);

        if (! $teammateUnavailable && ! $takeoverDue) {
            return $conversation;
        }

        $conversation->update([
            'reception_plan_version_id' => $this->currentPlanVersionId($channel),
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

        $conversation->refresh();
        $this->dispatchAiWelcomeAfterAiHandlingStatusChange($conversation, notify: false);

        return $conversation;
    }

    /**
     * 在客服已接待且无响应超时后将会话交给 AI 接待。
     */
    private function applyTeammateNoResponseAiTakeoverIfNeeded(Channel $channel, Conversation $conversation): Conversation
    {
        if (
            $conversation->assigned_user_id === null
            || $conversation->inbox_status !== ConversationInboxStatus::TeammateHandling
            || ! $this->aiAvailability->canUseAi($channel)
            || $this->isAiCooldownActive($conversation)
        ) {
            return $conversation;
        }

        $takeoverDue = $this->aiAvailability->teammateNoResponseAiTakeoverIsDue($channel, $conversation);

        if (! $takeoverDue) {
            return $conversation;
        }

        $conversation->update([
            'assigned_user_id' => null,
            'reception_plan_version_id' => $this->currentPlanVersionId($channel),
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

        $conversation->refresh();
        $this->dispatchAiWelcomeAfterAiHandlingStatusChange($conversation, notify: false);

        return $conversation;
    }

    /**
     * 判断会话是否处于 AI 不可用冷却期内，冷却期内不应自动切回 AI 接待。
     *
     * 通过查询最近一条 ai_unavailable 转人工事件的时间来判断，无需额外数据库字段。
     */
    private function isAiCooldownActive(Conversation $conversation): bool
    {
        $lastEvent = ConversationEvent::query()
            ->where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::HandoffRequested)
            ->where('payload->reason', 'ai_unavailable')
            ->latest('created_at')
            ->value('created_at');

        if ($lastEvent === null) {
            return false;
        }

        return Carbon::parse($lastEvent)->addSeconds(self::AI_COOLDOWN_SECONDS)->isFuture();
    }

    /**
     * 会话被创建或状态被改写为 AI 接待时，发送一次 AI 欢迎语。
     */
    private function dispatchAiWelcomeAfterAiHandlingStatusChange(Conversation $conversation, bool $notify): void
    {
        if ($conversation->inbox_status !== ConversationInboxStatus::AiHandling) {
            return;
        }

        $this->dispatchConversationAutoMessageAction->handle(
            $conversation,
            ConversationAutoMessageTrigger::AiWelcome,
            notify: $notify,
        );
    }

    /**
     * 决定新会话的初始 inbox_status。
     *
     * - AiFirst（AI 优先）：AI 可用则进入 AiHandling；AI 不可用则进入 TeammatePending。
     * - TeammateFirst（客服优先）：客服当前可接待则进入 TeammatePending；客服不可接待且 AI 可用则进入 AiHandling。
     *   客服不可接待包含两种情况：无可接待人员、或当前时刻不在营业时间内。
     * - 重点客户开启人工在线优先时，在线人工优先于 AI 优先策略。
     */
    private function resolveInitialInboxStatus(Channel $channel, Contact $contact): ConversationInboxStatus
    {
        $strategy = $this->strategyResolver->forChannel($channel);
        $canUseAi = $this->aiAvailability->canUseAi($channel);

        if (
            $contact->is_important
            && $strategy->important_contact_human_first_when_online_enabled
        ) {
            if ($this->isTeammateAvailable($channel)) {
                return ConversationInboxStatus::TeammatePending;
            }

            return $canUseAi
                ? ConversationInboxStatus::AiHandling
                : ConversationInboxStatus::TeammatePending;
        }

        if ($strategy->reception_mode === ReceptionRoutingMode::AiFirst && $canUseAi) {
            return ConversationInboxStatus::AiHandling;
        }

        if (! $this->isTeammateAvailable($channel) && $canUseAi) {
            return ConversationInboxStatus::AiHandling;
        }

        return ConversationInboxStatus::TeammatePending;
    }

    /**
     * 当前解析流程内复用人工服务状态。
     */
    private function isTeammateAvailable(Channel $channel): bool
    {
        $key = (string) $channel->id;
        if (! isset($this->teammateStatusCache[$key])) {
            $this->teammateStatusCache[$key] = $this->teammateAvailability->serviceStatus($channel)->human_available;
        }

        return $this->teammateStatusCache[$key];
    }

    /**
     * 解析渠道当前生效的接待方案版本 ID（绑定方案的最新已发布版），用于会话锁定快照。
     * 同一渠道在本次解析内只解析一次。
     */
    private function currentPlanVersionId(Channel $channel): ?string
    {
        $channelId = (string) $channel->id;

        if (! array_key_exists($channelId, $this->channelPlanVersionIdCache)) {
            $version = $this->activePlanVersionResolver->currentVersionForChannel($channel);
            $this->channelPlanVersionIdCache[$channelId] = $version?->id !== null ? (string) $version->id : null;
        }

        return $this->channelPlanVersionIdCache[$channelId];
    }

    /**
     * 构造访客在当前渠道下的开放会话查询。
     */
    private function openConversationQuery(Channel $channel, Contact $contact): Builder
    {
        return Conversation::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('status', ConversationStatus::Open);
    }
}
