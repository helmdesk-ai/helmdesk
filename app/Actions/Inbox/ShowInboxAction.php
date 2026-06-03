<?php

namespace App\Actions\Inbox;

use App\Actions\Contact\ShowContactConversationTimelineAction;
use App\Actions\Conversation\GetContactConversationTagAggregatesAction;
use App\Actions\Translation\ResolveConversationTranslationProviderAction;
use App\Data\Conversation\ConversationContactSummaryData;
use App\Data\Conversation\ConversationSummaryData;
use App\Data\Conversation\ListConversationItemData;
use App\Data\CustomAttribute\ContactAttributeFieldData;
use App\Data\EnumOptionData;
use App\Data\Inbox\EnabledWebChannelData;
use App\Data\Inbox\InboxContactProfileData;
use App\Data\Inbox\InboxFiltersData;
use App\Data\Inbox\InboxSelectionData;
use App\Data\Inbox\InboxTabCountsData;
use App\Data\Inbox\ShowInboxPagePropsData;
use App\Data\SystemUserContextData;
use App\Data\Tag\TagOptionData;
use App\Data\User\UserOptionData;
use App\Enums\ChannelType;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\InboxView;
use App\Enums\ReceptionLanguage;
use App\Enums\ReplyAssistantMode;
use App\Enums\ReplyPolishTone;
use App\Enums\TagScope;
use App\Models\AttributeDefinition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactAttributeValue;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use App\Services\Reception\ChannelAiAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 组装收件箱的列表、选中会话和右侧上下文。
 */
class ShowInboxAction
{
    use AsAction;

    private const LIST_LIMIT = 50;

    /**
     * 注入联系人时间线与渠道 AI 可用性服务。
     */
    public function __construct(
        private readonly ShowContactConversationTimelineAction $contactTimelineAction,
        private readonly ChannelAiAvailability $aiAvailability,
        private readonly GetContactConversationTagAggregatesAction $conversationTagAggregates,
        private readonly ResolveConversationTranslationProviderAction $translationProviderResolver,
    ) {}

    /**
     * 组装收件箱当前筛选条件下的列表、选中会话和统计数据。
     */
    public function handle(
        User $user,
        InboxFiltersData $filters,
        ?string $conversationId = null,
    ): ShowInboxPagePropsData {
        $filters = $this->normalizeFilters($filters);

        $conversations = $this->buildQuery($user, $filters)
            ->select('conversations.*')
            ->with(['contact', 'receptionPlanVersion.plan', 'assignedUser', 'channel', 'latestMessage'])
            ->when(
                $filters->view !== InboxView::Closed,
                fn (Builder $query) => $query
                    ->join('contacts as inbox_contacts', 'inbox_contacts.id', '=', 'conversations.contact_id')
                    ->orderByDesc('inbox_contacts.is_important')
            )
            ->orderByRaw('COALESCE(conversations.closed_at, conversations.last_message_at, conversations.created_at) DESC')
            ->orderByDesc('conversations.created_at')
            ->orderByDesc('conversations.id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $this->attachUnreadCounts($conversations, $user);

        $selectionId = null;
        $selection = $this->resolveSelection(
            user: $user,
            conversations: $conversations,
            conversationId: $conversationId,
            filters: $filters,
            selectionId: $selectionId,
        );

        return new ShowInboxPagePropsData(
            current_view: $filters->view,
            current_channel_id: $filters->channel_id,
            current_assignee: $filters->assignee,
            current_search: $filters->search,
            current_important_only: $filters->important_only,
            current_conversation_id: $selectionId,
            enabled_web_channels: $this->loadEnabledWebChannels(),
            teammates: $this->loadTeammates($user),
            conversation_list: $conversations
                ->map(fn (Conversation $conversation) => ListConversationItemData::fromModel($conversation, $user))
                ->all(),
            selection: $selection,
            available_contact_tags: $this->loadAvailableContactTags(),
            available_conversation_tags: $this->loadAvailableConversationTags(),
            reception_language_options: EnumOptionData::fromCases(ReceptionLanguage::cases()),
            reply_assistant_mode_options: EnumOptionData::fromCases(ReplyAssistantMode::cases()),
            reply_polish_tone_options: EnumOptionData::fromCases(ReplyPolishTone::cases()),
            tab_counts: $this->computeTabCounts($user),
        );
    }

    /**
     * Tab 待关注数量；只有“我负责的”使用未读访客消息语义，非本人会话不计入个人未读。
     */
    private function computeTabCounts(User $user): InboxTabCountsData
    {
        $pending = (int) Conversation::query()
            ->where('status', ConversationStatus::Open)
            ->where('inbox_status', ConversationInboxStatus::TeammatePending)
            ->count();

        return new InboxTabCountsData(
            pending: $pending,
            ai: 0,
            mine: $this->countMyOpenConversationsWithUnreadVisitorMessages($user),
            teammates: 0,
        );
    }

    /**
     * 统计当前用户负责的开放会话中仍未读的访客消息会话数。
     */
    private function countMyOpenConversationsWithUnreadVisitorMessages(User $user): int
    {
        return (int) Conversation::query()
            ->where('status', ConversationStatus::Open)
            ->where('assigned_user_id', $user->id)
            ->where('unread_visitor_message_count', '>', 0)
            ->count();
    }

    /**
     * 返回工作台收件箱页面。
     */
    public function asController(Request $request): Response
    {
        $ctx = SystemUserContextData::fromRequest($request);

        $filters = InboxFiltersData::fromRequest($request);
        $conversationId = is_string($request->query('conversation_id')) ? $request->query('conversation_id') : null;

        $props = $this->handle(
            user: User::query()->findOrFail($ctx->user_id),
            filters: $filters,
            conversationId: $conversationId,
        );

        return Inertia::render('Inbox', $props->toArray());
    }

    private function normalizeFilters(InboxFiltersData $filters): InboxFiltersData
    {
        $channelId = $filters->channel_id;
        if ($channelId !== null && ! $this->channelBelongsToSystem($channelId)) {
            throw ValidationException::withMessages([
                'channel' => __('validation.exists', ['attribute' => 'channel']),
            ]);
        }

        $assignee = $filters->assignee;
        if (
            $assignee !== null
            && $assignee !== InboxFiltersData::ASSIGNEE_UNASSIGNED
            && ! $this->userBelongsToSystem($assignee)
        ) {
            throw ValidationException::withMessages([
                'assignee' => __('validation.exists', ['attribute' => 'assignee']),
            ]);
        }

        return new InboxFiltersData(
            view: $filters->view,
            channel_id: $channelId,
            assignee: $assignee,
            search: $filters->search,
            important_only: $filters->important_only,
        );
    }

    /**
     * 构造收件箱会话列表查询。
     */
    private function buildQuery(User $user, InboxFiltersData $filters): Builder
    {
        $query = Conversation::query();

        $this->applyView($query, $user, $filters);
        $this->applyChannelFilter($query, $filters->channel_id);
        $this->applyImportantFilter($query, $filters->important_only);
        $this->applySearchFilter($query, $filters->search);

        return $query;
    }

    /**
     * 按重点客户筛选收件箱会话。
     */
    private function applyImportantFilter(Builder $query, bool $importantOnly): void
    {
        if (! $importantOnly) {
            return;
        }

        $query->whereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('is_important', true));
    }

    /**
     * 对会话摘要、最近消息和联系人信息应用搜索条件。
     */
    private function applySearchFilter(Builder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function (Builder $searchQuery) use ($like): void {
            $searchQuery
                ->where('conversations.subject', 'like', $like)
                ->orWhere('conversations.summary', 'like', $like)
                ->orWhere('conversations.last_message_preview', 'like', $like)
                ->orWhereHas('contact', function (Builder $contactQuery) use ($like): void {
                    $contactQuery
                        ->where('name', 'like', $like)
                        ->orWhere('primary_email', 'like', $like)
                        ->orWhere('primary_phone', 'like', $like);
                });
        });
    }

    /**
     * 应用主视图条件。Closed 视图自己接管 assignee 处理（语义不对称），
     * 其他视图则在这里独立叠加 assignee 筛选——三个维度可自由组合，
     * 出现「view=mine&assignee=other_user」这种逻辑矛盾时自然返回空集即可。
     */
    private function applyView(Builder $query, User $user, InboxFiltersData $filters): void
    {
        match ($filters->view) {
            InboxView::Pending => $query
                ->where('conversations.status', ConversationStatus::Open)
                ->where('conversations.inbox_status', ConversationInboxStatus::TeammatePending),
            InboxView::Mine => $query
                ->where('conversations.status', ConversationStatus::Open)
                ->where('conversations.assigned_user_id', $user->id),
            InboxView::Ai => $query
                ->where('conversations.status', ConversationStatus::Open)
                ->whereNull('conversations.assigned_user_id')
                ->where('conversations.inbox_status', ConversationInboxStatus::AiHandling),
            InboxView::Teammates => $query
                ->where('conversations.status', ConversationStatus::Open)
                ->whereNotNull('conversations.assigned_user_id')
                ->where('conversations.assigned_user_id', '!=', $user->id)
                ->where('conversations.inbox_status', ConversationInboxStatus::TeammateHandling),
            InboxView::Closed => $this->applyClosedView($query, $user, $filters->assignee),
        };

        if ($filters->view !== InboxView::Closed) {
            $this->applyOrthogonalAssigneeFilter($query, $filters->assignee);
        }
    }

    /**
     * Closed 视图的精确语义（与文档对齐）：
     * - 不带 assignee：assigned_user_id = current_user OR 当前用户亲手关闭过的会话
     * - assignee=unassigned：assigned_user_id IS NULL
     * - assignee=user_id：assigned_user_id = user_id（不再叠加「亲手关闭」，按 assignment 看更稳定）
     *
     * 三种情况共享：
     * - 排除"同一联系人 + 同一渠道下还有进行中会话"的项
     * - 同一联系人 + 同一渠道的历史关闭会话只展示最近一条，完整历史留给时间线承载
     */
    private function applyClosedView(Builder $query, User $user, ?string $assignee): void
    {
        $query
            ->where('conversations.status', ConversationStatus::Closed)
            ->whereNotExists(function ($openConversation): void {
                $openConversation
                    ->selectRaw('1')
                    ->from('conversations as open_conversations')
                    ->whereColumn('open_conversations.contact_id', 'conversations.contact_id')
                    ->whereColumn('open_conversations.channel_id', 'conversations.channel_id')
                    ->where('open_conversations.status', ConversationStatus::Open);
            });

        $this->applyClosedAssigneeScope($query, $user, $assignee);
        $this->applyLatestClosedPerContactChannel($query, $user, $assignee);
    }

    /**
     * 为关闭视图应用负责人筛选语义。
     */
    private function applyClosedAssigneeScope(Builder $query, User $user, ?string $assignee): void
    {
        if ($assignee === null) {
            $query->where(function (Builder $sub) use ($user): void {
                $sub->where('conversations.assigned_user_id', $user->id)
                    ->orWhereHas('events', function (Builder $eventQuery) use ($user): void {
                        $eventQuery
                            ->where('actor_user_id', $user->id)
                            ->where('type', ConversationEventType::StatusChanged)
                            ->where('payload->status', ConversationStatus::Closed);
                    });
            });

            return;
        }

        if ($assignee === InboxFiltersData::ASSIGNEE_UNASSIGNED) {
            $query->whereNull('conversations.assigned_user_id');

            return;
        }

        $query->where('conversations.assigned_user_id', $assignee);
    }

    /**
     * 关闭视图中同一联系人和渠道只保留最近一条会话。
     */
    private function applyLatestClosedPerContactChannel(Builder $query, User $user, ?string $assignee): void
    {
        $query->whereNotExists(function ($newerConversation) use ($user, $assignee): void {
            $newerConversation
                ->selectRaw('1')
                ->from('conversations as newer_closed_conversations')
                ->where('newer_closed_conversations.status', ConversationStatus::Closed);

            $this->whereSameNullableColumn($newerConversation, 'newer_closed_conversations.contact_id', 'conversations.contact_id');
            $this->whereSameNullableColumn($newerConversation, 'newer_closed_conversations.channel_id', 'conversations.channel_id');
            $this->applyClosedAssigneeScopeToTable($newerConversation, $user, $assignee, 'newer_closed_conversations');
            $this->whereClosedConversationIsNewer($newerConversation);
        });
    }

    /**
     * 在子查询表别名上复用关闭视图负责人筛选。
     */
    private function applyClosedAssigneeScopeToTable(QueryBuilder $query, User $user, ?string $assignee, string $table): void
    {
        if ($assignee === null) {
            $query->where(function ($sub) use ($table, $user): void {
                $sub->where($table.'.assigned_user_id', $user->id)
                    ->orWhereExists(function ($eventQuery) use ($table, $user): void {
                        $eventQuery
                            ->selectRaw('1')
                            ->from('conversation_events')
                            ->whereColumn('conversation_events.conversation_id', $table.'.id')
                            ->where('conversation_events.actor_user_id', $user->id)
                            ->where('conversation_events.type', ConversationEventType::StatusChanged)
                            ->where('conversation_events.payload->status', ConversationStatus::Closed);
                    });
            });

            return;
        }

        if ($assignee === InboxFiltersData::ASSIGNEE_UNASSIGNED) {
            $query->whereNull($table.'.assigned_user_id');

            return;
        }

        $query->where($table.'.assigned_user_id', $assignee);
    }

    /**
     * 比较两个可为空列是否表示同一个值。
     */
    private function whereSameNullableColumn(QueryBuilder $query, string $leftColumn, string $rightColumn): void
    {
        $query->where(function ($sameColumn) use ($leftColumn, $rightColumn): void {
            $sameColumn
                ->whereColumn($leftColumn, $rightColumn)
                ->orWhere(function ($bothNull) use ($leftColumn, $rightColumn): void {
                    $bothNull
                        ->whereNull($leftColumn)
                        ->whereNull($rightColumn);
                });
        });
    }

    /**
     * 判断子查询中的关闭会话是否比当前行更新。
     */
    private function whereClosedConversationIsNewer(QueryBuilder $query): void
    {
        $newerActivity = 'COALESCE(newer_closed_conversations.closed_at, newer_closed_conversations.last_message_at, newer_closed_conversations.created_at)';
        $currentActivity = 'COALESCE(conversations.closed_at, conversations.last_message_at, conversations.created_at)';

        $query->where(function ($newer) use ($newerActivity, $currentActivity): void {
            $newer
                ->whereRaw($newerActivity.' > '.$currentActivity)
                ->orWhere(function ($sameActivityNewerCreatedAt) use ($newerActivity, $currentActivity): void {
                    $sameActivityNewerCreatedAt
                        ->whereRaw($newerActivity.' = '.$currentActivity)
                        ->whereColumn('newer_closed_conversations.created_at', '>', 'conversations.created_at');
                })
                ->orWhere(function ($sameActivitySameCreatedAtNewerId) use ($newerActivity, $currentActivity): void {
                    $sameActivitySameCreatedAtNewerId
                        ->whereRaw($newerActivity.' = '.$currentActivity)
                        ->whereColumn('newer_closed_conversations.created_at', 'conversations.created_at')
                        ->whereColumn('newer_closed_conversations.id', '>', 'conversations.id');
                });
        });
    }

    /**
     * 应用网站渠道筛选条件。
     */
    private function applyChannelFilter(Builder $query, ?string $channelId): void
    {
        if ($channelId === null) {
            return;
        }

        $query->where('conversations.channel_id', $channelId);
    }

    /**
     * 在非关闭视图上独立叠加负责人筛选。
     */
    private function applyOrthogonalAssigneeFilter(Builder $query, ?string $assignee): void
    {
        if ($assignee === null) {
            return;
        }

        if ($assignee === InboxFiltersData::ASSIGNEE_UNASSIGNED) {
            $query->whereNull('conversations.assigned_user_id');

            return;
        }

        $query->where('conversations.assigned_user_id', $assignee);
    }

    /**
     * 计算每条会话里当前用户仍未读的访客消息数。
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    private function attachUnreadCounts(Collection $conversations, User $user): void
    {
        foreach ($conversations as $conversation) {
            $conversation->unread_count = (string) $conversation->assigned_user_id === (string) $user->id
                ? (int) $conversation->unread_visitor_message_count
                : 0;
        }
    }

    /**
     * 查询可用于收件箱筛选的网站渠道。
     *
     * @return EnabledWebChannelData[]
     */
    private function loadEnabledWebChannels(): array
    {
        return Channel::query()
            ->where('type', ChannelType::Web)
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel) => EnabledWebChannelData::fromModel($channel))
            ->all();
    }

    /**
     * 查询其他后台用户选项。
     *
     * @return UserOptionData[]
     */
    private function loadTeammates(User $currentUser): array
    {
        return User::query()
            ->whereKeyNot($currentUser->id)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => UserOptionData::fromModel($user))
            ->all();
    }

    /**
     * 查询收件箱可用的联系人维度标签选项。
     *
     * @return TagOptionData[]
     */
    private function loadAvailableContactTags(): array
    {
        return $this->loadAvailableTagsForScope(TagScope::Contact);
    }

    /**
     * 查询收件箱可用的会话维度标签选项（供摘要块上人工打标签选择器使用）。
     *
     * @return TagOptionData[]
     */
    private function loadAvailableConversationTags(): array
    {
        return $this->loadAvailableTagsForScope(TagScope::Conversation);
    }

    /**
     * 按适用维度查询标签选项。
     *
     * @return TagOptionData[]
     */
    private function loadAvailableTagsForScope(TagScope $scope): array
    {
        return Tag::query()
            ->whereHas('tagGroup', fn (Builder $query) => $query->where('scope', $scope->value))
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag) => TagOptionData::fromModel($tag))
            ->all();
    }

    /**
     * 解析当前列表中应选中的会话及右侧面板数据。
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    private function resolveSelection(
        User $user,
        Collection $conversations,
        ?string $conversationId,
        InboxFiltersData $filters,
        ?string &$selectionId = null,
    ): ?InboxSelectionData {
        $selected = null;

        if ($conversationId !== null) {
            $selected = $conversations->firstWhere('id', $conversationId);
            if ($selected === null && $filters->view !== InboxView::Closed) {
                $selected = Conversation::query()
                    ->with(['contact', 'receptionPlanVersion.plan', 'assignedUser', 'channel'])
                    ->find($conversationId);
            }
        }

        if ($selected === null) {
            $selected = $conversations->first();
        }

        if ($selected === null) {
            $selectionId = null;

            return null;
        }

        $selected->loadMissing(['contact.tags', 'tags', 'receptionPlanVersion.plan', 'assignedUser', 'channel']);
        $selected->loadCount(['messages as display_message_count' => Conversation::displayMessageCountQuery()]);

        $stitched = $this->contactTimelineAction->handle(
            $selected->contact ?? new Contact,
            viewer: $user,
        );
        $customAttributes = $selected->contact
            ? $this->buildCustomAttributeFields($selected->contact)
            : [];
        $conversationTagAggregates = $selected->contact
            ? $this->conversationTagAggregates->handle((string) $selected->contact->id)
            : [];

        $isOpen = $selected->status === ConversationStatus::Open;
        $selectionId = (string) $selected->id;
        $isAiOwned = $selected->assigned_user_id === null
            && $selected->inbox_status === ConversationInboxStatus::AiHandling;
        $isAssignedToCurrentUser = $selected->assigned_user_id !== null
            && (string) $selected->assigned_user_id === (string) $user->id;
        $isAssignedToAnotherUser = $selected->assigned_user_id !== null
            && ! $isAssignedToCurrentUser;
        $canClaim = $isOpen && (
            $isAiOwned
            || $selected->inbox_status === ConversationInboxStatus::TeammatePending
            || (
                $isAssignedToAnotherUser
                && $selected->inbox_status === ConversationInboxStatus::TeammateHandling
            )
        );
        $canTransferToTeammate = $isOpen
            && $isAssignedToCurrentUser
            && $selected->inbox_status === ConversationInboxStatus::TeammateHandling;
        $canReleaseToAi = $isOpen
            && $isAssignedToCurrentUser
            && $selected->inbox_status === ConversationInboxStatus::TeammateHandling;
        $releaseToAiWillUseAi = $canReleaseToAi && $this->conversationCanUseAi($selected);
        $canTranslateMessages = $this->translationProviderResolver->hasUsableProvider($selected);

        return new InboxSelectionData(
            conversation: ConversationSummaryData::fromModel($selected),
            contact: $selected->contact ? ConversationContactSummaryData::fromModel($selected->contact) : null,
            contact_profile: $selected->contact ? InboxContactProfileData::fromModel($selected->contact, $customAttributes, $conversationTagAggregates) : null,
            stitched_timeline: $stitched,
            can_reply: $isOpen && ! $isAiOwned && ! $isAssignedToAnotherUser,
            can_claim: $canClaim,
            can_transfer_to_teammate: $canTransferToTeammate,
            can_release_to_ai: $canReleaseToAi,
            release_to_ai_will_use_ai: $releaseToAiWillUseAi,
            can_close: $isOpen && ! $isAssignedToAnotherUser,
            can_reopen: ! $isOpen && ! $this->hasOpenConversationForSameContactChannel($selected),
            can_translate_messages: $canTranslateMessages,
            reply_visitor_locale: $selected->visitor_locale,
        );
    }

    /**
     * 判断当前会话是否还能释放给 AI 接待。
     */
    private function conversationCanUseAi(Conversation $conversation): bool
    {
        if ($conversation->channel === null) {
            return true;
        }

        return $this->aiAvailability->canUseAi($conversation->channel);
    }

    /**
     * 判断同一联系人和渠道下是否还有进行中的会话。
     */
    private function hasOpenConversationForSameContactChannel(Conversation $conversation): bool
    {
        if ($conversation->contact_id === null || $conversation->channel_id === null) {
            return false;
        }

        return Conversation::query()
            ->where('contact_id', $conversation->contact_id)
            ->where('channel_id', $conversation->channel_id)
            ->where('status', ConversationStatus::Open)
            ->whereKeyNot($conversation->id)
            ->exists();
    }

    /**
     * 组装联系人资料面板里的自定义属性字段。
     *
     * @return ContactAttributeFieldData[]
     */
    private function buildCustomAttributeFields(Contact $contact): array
    {
        $activeDefinitions = AttributeDefinition::query()
            ->active()
            ->ordered()
            ->get();

        $contactValues = ContactAttributeValue::query()
            ->where('contact_id', $contact->id)
            ->with('definition')
            ->get()
            ->keyBy('definition_id');

        $deletedWithValues = $contactValues
            ->filter(fn (ContactAttributeValue $value) => $value->definition?->trashed())
            ->map(fn (ContactAttributeValue $value) => $value->definition)
            ->filter();

        $fields = [];

        foreach ($activeDefinitions->merge($deletedWithValues) as $definition) {
            $value = $contactValues->get($definition->id);

            $fields[] = new ContactAttributeFieldData(
                definition_id: $definition->id,
                key: $definition->key,
                name: $definition->name,
                description: $definition->description,
                type: $definition->type->value,
                type_label: $definition->type->label(),
                config: $definition->config,
                value: $value?->value(),
                source: $value?->source?->value,
                source_label: $value?->source?->label(),
                deleted_at: $definition->deleted_at?->toIso8601String(),
                is_editable: ! $definition->trashed(),
            );
        }

        return $fields;
    }

    /**
     * 判断渠道筛选值是否存在。
     */
    private function channelBelongsToSystem(string $channelId): bool
    {
        return Channel::query()
            ->whereKey($channelId)
            ->exists();
    }

    /**
     * 判断负责人筛选值是否存在。
     */
    private function userBelongsToSystem(string $userId): bool
    {
        return User::query()->whereKey($userId)->exists();
    }
}
