<?php

namespace App\Actions\Conversation;

use App\Actions\Reception\Plan\ListReceptionPlansForChannelSelectionAction;
use App\Data\Conversation\ListConversationItemData;
use App\Data\Conversation\ShowConversationListPagePropsData;
use App\Data\EnumOptionData;
use App\Data\SimplePaginationData;
use App\Data\SystemUserContextData;
use App\Data\Tag\TagOptionData;
use App\Data\User\UserOptionData;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\ConversationVisitorReplyStatus;
use App\Enums\TagMatchMode;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\SystemContext;
use App\Models\Tag;
use App\Models\User;
use App\Services\Search\ConversationMessageSearch;
use App\Services\Search\ConversationMessageVisibleTextResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询会话列表，并处理搜索、状态筛选。
 */
class ShowConversationListAction
{
    use AsAction;

    /**
     * 注入接待方案选项查询，让筛选器可以按方案过滤会话。
     */
    public function __construct(
        private readonly ListReceptionPlansForChannelSelectionAction $listReceptionPlans,
        private readonly ConversationMessageSearch $messageSearch,
        private readonly ConversationMessageVisibleTextResolver $visibleTextResolver,
    ) {}

    /**
     * 按搜索、状态、收件箱、访客回复、分配、接待方案版本筛选条件查询会话列表页 props。
     */
    public function handle(
        SystemContext $systemContext,
        ?string $search = null,
        int $page = 1,
        int $perPage = 15,
        ?ConversationStatus $status = null,
        ?ConversationInboxStatus $inboxStatus = null,
        ?ConversationVisitorReplyStatus $visitorReplyStatus = null,
        ?string $assignedUserId = null,
        ?string $receptionPlanId = null,
        ?string $currentUserId = null,
    ): ShowConversationListPagePropsData {
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);

        $currentUser = $currentUserId !== null ? User::query()->find($currentUserId) : null;
        $query = $systemContext->conversations()->with(['contact', 'receptionPlanVersion.plan', 'assignedUser', 'channel', 'latestMessage']);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($inboxStatus !== null) {
            $query->where('inbox_status', $inboxStatus);
        }

        if ($visitorReplyStatus === ConversationVisitorReplyStatus::Waiting) {
            $query->where('waiting_for_visitor_reply', true);
        } elseif ($visitorReplyStatus === ConversationVisitorReplyStatus::NotWaiting) {
            $query->where('waiting_for_visitor_reply', false);
        }

        if ($assignedUserId === 'mine' && $currentUserId !== null) {
            $query->where('assigned_user_id', $currentUserId);
        } elseif ($assignedUserId === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif (filled($assignedUserId)) {
            $query->where('assigned_user_id', $assignedUserId);
        }

        if (filled($receptionPlanId)) {
            $query->whereHas('receptionPlanVersion', function ($versionQuery) use ($receptionPlanId) {
                $versionQuery->where('reception_plan_id', $receptionPlanId);
            });
        }

        if (filled($search)) {
            $messageMatchedConversationIds = $this->collectConversationIdsMatchingMessageContent(
                $systemContext,
                $currentUser,
                $search,
            );

            $conversationIdColumn = $query->getModel()->getQualifiedKeyName();

            $query->where(function ($searchQuery) use ($search, $messageMatchedConversationIds, $conversationIdColumn) {
                $searchQuery
                    ->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('summary', 'like', '%'.$search.'%')
                    ->orWhere('last_message_preview', 'like', '%'.$search.'%')
                    ->orWhereHas('contact', function ($contactQuery) use ($search) {
                        $contactQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('primary_email', 'like', '%'.$search.'%')
                            ->orWhere('primary_phone', 'like', '%'.$search.'%');
                    });

                if ($messageMatchedConversationIds !== []) {
                    $searchQuery->orWhereIn($conversationIdColumn, $messageMatchedConversationIds);
                }
            });
        }

        $availableContactTagModels = Tag::query()
            ->orderBy('name')
            ->get();

        $paginator = $query
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $teammates = $systemContext->users()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => UserOptionData::fromModel($user))
            ->all();

        return new ShowConversationListPagePropsData(
            conversation_list: $paginator->getCollection()
                ->map(fn (Conversation $conversation) => ListConversationItemData::fromModel($conversation, $currentUser))
                ->all(),
            conversation_list_pagination: SimplePaginationData::fromPaginator($paginator),
            status_options: EnumOptionData::fromCases(ConversationStatus::cases()),
            inbox_status_options: EnumOptionData::fromCases(ConversationInboxStatus::cases()),
            visitor_reply_status_options: EnumOptionData::fromCases(ConversationVisitorReplyStatus::cases()),
            source_options: EnumOptionData::fromCases(ConversationSource::cases()),
            tag_match_mode_options: EnumOptionData::fromCases(TagMatchMode::cases()),
            search: $search,
            current_status: $status,
            current_inbox_status: $inboxStatus,
            current_visitor_reply_status: $visitorReplyStatus,
            current_assigned_user_id: $assignedUserId,
            current_reception_plan_id: $receptionPlanId,
            available_contact_tags: $availableContactTagModels
                ->map(fn (Tag $tag) => TagOptionData::fromModel($tag))
                ->all(),
            teammate_options: $teammates,
            reception_plan_options: $this->listReceptionPlans->handle($systemContext),
        );
    }

    /**
     * @return list<string>
     */
    private function collectConversationIdsMatchingMessageContent(SystemContext $systemContext, ?User $viewer, string $search): array
    {
        $perPage = 200;
        $maxPages = 25;
        $maxUniqueConversations = 500;

        $seen = [];
        $ordered = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $paginator = $this->messageSearch->query($search)
                ->paginate($perPage, 'page', $page);
            $messagesById = ConversationMessage::query()
                ->with(['conversation.channel', 'conversation.contact'])
                ->whereIn('id', collect($paginator->items())->pluck('id')->all())
                ->get()
                ->keyBy('id');

            foreach ($paginator->items() as $message) {
                $message = $messagesById[(string) $message->id];
                $conversationId = $message->conversation_id;

                $matchesVisibleText = $this->messageSearch->matches(
                    $search,
                    $this->visibleTextResolver->texts($message, $viewer),
                );

                if (isset($seen[$conversationId]) || ! $matchesVisibleText) {
                    continue;
                }

                $seen[$conversationId] = true;
                $ordered[] = $conversationId;

                if (count($ordered) >= $maxUniqueConversations) {
                    return $ordered;
                }
            }

            if (! $paginator->hasMorePages()) {
                break;
            }
        }

        return $ordered;
    }

    /**
     * 返回系统会话列表页面。
     */
    public function asController(Request $request): Response
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $systemContext = $ctx->systemContext();
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn (ConversationStatus $status) => $status->value, ConversationStatus::cases()))],
            'inbox_status' => ['nullable', Rule::in(array_map(fn (ConversationInboxStatus $status) => $status->value, ConversationInboxStatus::cases()))],
            'visitor_reply_status' => ['nullable', Rule::in(array_map(fn (ConversationVisitorReplyStatus $status) => $status->value, ConversationVisitorReplyStatus::cases()))],
        ]);

        $props = $this->handle(
            systemContext: $systemContext,
            search: $request->query('search'),
            page: (int) $request->query('page', 1),
            status: isset($validated['status']) ? ConversationStatus::from($validated['status']) : null,
            inboxStatus: isset($validated['inbox_status']) ? ConversationInboxStatus::from($validated['inbox_status']) : null,
            visitorReplyStatus: isset($validated['visitor_reply_status']) ? ConversationVisitorReplyStatus::from($validated['visitor_reply_status']) : null,
            assignedUserId: is_string($request->query('assigned_user_id')) ? $request->query('assigned_user_id') : null,
            receptionPlanId: is_string($request->query('reception_plan_id')) ? $request->query('reception_plan_id') : null,
            currentUserId: $ctx->user_id,
        );

        return Inertia::render('contacts/Conversation', $props->toArray());
    }
}
