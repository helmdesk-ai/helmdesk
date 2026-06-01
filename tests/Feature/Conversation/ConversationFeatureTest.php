<?php

use App\Actions\Conversation\ShowConversationDetailAction;
use App\Actions\Conversation\ShowConversationListAction;
use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\ConversationEventTone;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Enums\Reception\HumanServiceUnavailableReason;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
});

test('已认证用户可以查看会话列表页面', function () {
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Alice Example',
        'primary_email' => 'alice@example.com',
    ]);
    $plan = ReceptionPlan::factory()->for($this->workspace)->create([
        'name' => 'Triage Plan-'.Str::lower(Str::random(6)),
    ]);
    $version = ReceptionPlanVersion::factory()->for($plan, 'plan')->create();

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'workspace_id' => $this->workspace->id,
            'reception_plan_version_id' => $version->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'subject' => 'Billing follow-up',
            'last_message_preview' => 'Can you help with my invoice?',
            'last_message_at' => now(),
        ]);

    $props = ShowConversationListAction::run(
        workspace: $this->workspace,
        currentUserId: $this->user->id,
    );

    expect($props->conversation_list)->toHaveCount(1)
        ->and($props->conversation_list[0]->id)->toBe($conversation->id)
        ->and($props->conversation_list[0]->subject)->toBe('Billing follow-up')
        ->and($props->conversation_list[0]->contact_name)->toBe('Alice Example')
        ->and($props->conversation_list[0]->reception_plan_version_id)->toBe($version->id)
        ->and($props->conversation_list[0]->reception_plan_name)->toBe($plan->name)
        ->and($props->conversation_list[0]->reception_plan_version_number)->toBe($version->version_number)
        ->and($props->conversation_list[0]->assigned_user_name)->toBe($this->user->name)
        ->and($props->conversation_list[0]->status->value)->toBe(ConversationStatus::Open->value)
        ->and($props->conversation_list[0]->inbox_status->value)->toBe(ConversationInboxStatus::TeammateHandling->value)
        ->and($props->conversation_list[0]->inbox_status->label)->toBe('Human handling');
});

test('会话列表支持已分配用户筛选', function () {
    $otherUser = User::factory()->create();
    $otherUser->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Admin->value]);

    $mine = Conversation::factory()->assignedTo($this->user)->create([
        'workspace_id' => $this->workspace->id,
        'subject' => 'Mine',
        'last_message_at' => now()->subMinute(),
    ]);

    Conversation::factory()->assignedTo($otherUser)->create([
        'workspace_id' => $this->workspace->id,
        'subject' => 'Other',
        'last_message_at' => now()->subMinutes(2),
    ]);

    $unassigned = Conversation::factory()->unassigned()->create([
        'workspace_id' => $this->workspace->id,
        'subject' => 'Unassigned',
        'last_message_at' => now()->subMinutes(3),
    ]);

    $mineProps = ShowConversationListAction::run(
        workspace: $this->workspace,
        assignedUserId: 'mine',
        currentUserId: $this->user->id,
    );

    expect($mineProps->conversation_list)->toHaveCount(1)
        ->and($mineProps->conversation_list[0]->id)->toBe($mine->id)
        ->and($mineProps->current_assigned_user_id)->toBe('mine');

    $unassignedProps = ShowConversationListAction::run(
        workspace: $this->workspace,
        assignedUserId: 'unassigned',
        currentUserId: $this->user->id,
    );

    expect($unassignedProps->conversation_list)->toHaveCount(1)
        ->and($unassignedProps->conversation_list[0]->id)->toBe($unassigned->id)
        ->and($unassignedProps->current_assigned_user_id)->toBe('unassigned');
});

test('已关闭会话列表项展示关闭状态并保留收件箱状态', function () {
    $conversation = Conversation::factory()
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'workspace_id' => $this->workspace->id,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'subject' => 'Closed conversation',
            'last_message_at' => now(),
        ]);

    $props = ShowConversationListAction::run(
        workspace: $this->workspace,
        status: ConversationStatus::Closed,
        currentUserId: $this->user->id,
    );

    expect($props->conversation_list)->toHaveCount(1)
        ->and($props->conversation_list[0]->id)->toBe($conversation->id)
        ->and($props->conversation_list[0]->status->label)->toBe('Closed')
        ->and($props->conversation_list[0]->inbox_status->label)->toBe('Human handling');
});

test('会话列表项按当前用户语言展示访客消息译文摘要', function () {
    $this->user->update(['locale' => 'zh-CN']);
    $channel = Channel::factory()->for($this->workspace)->create();
    $contact = Contact::factory()->create(['workspace_id' => $this->workspace->id]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'workspace_id' => $this->workspace->id,
            'last_message_preview' => 'Hello, I need help',
            'last_message_at' => now(),
        ]);
    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'Hello, I need help',
        'payload' => [
            'translations' => [
                'zh-CN' => ['text' => '你好，我需要帮助'],
            ],
        ],
    ]);

    $props = ShowConversationListAction::run(
        workspace: $this->workspace,
        currentUserId: $this->user->id,
    );

    expect($props->conversation_list)->toHaveCount(1)
        ->and($props->conversation_list[0]->last_message_preview)->toBe('Hello, I need help')
        ->and($props->conversation_list[0]->display_last_message_preview)->toBe('你好，我需要帮助');
});

test('会话详情返回已合并时间线在升序顺序', function () {
    $this->user->forceFill([
        'avatar' => 'https://example.com/operator.png',
    ])->save();

    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Alice Example',
    ]);
    $plan = ReceptionPlan::factory()->for($this->workspace)->create([
        'name' => 'Support Plan-'.Str::lower(Str::random(6)),
    ]);
    $version = ReceptionPlanVersion::factory()->for($plan, 'plan')->create();

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'workspace_id' => $this->workspace->id,
            'reception_plan_version_id' => $version->id,
            'subject' => 'Timeline test',
        ]);

    $firstAt = CarbonImmutable::parse('2026-04-18 09:00:00');
    $secondAt = $firstAt->addMinutes(1);
    $thirdAt = $secondAt->addMinutes(1);
    $fourthAt = $thirdAt->addMinutes(1);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => null,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'Hello there',
        'created_at' => $firstAt,
        'updated_at' => $firstAt,
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'actor_user_id' => $this->user->id,
        'type' => ConversationEventType::Created,
        'payload' => ['source' => 'manual'],
        'created_at' => $secondAt,
    ]);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => null,
        'role' => MessageRole::Ai,
        'kind' => MessageKind::Text,
        'content' => 'I can help with that.',
        'created_at' => $thirdAt,
        'updated_at' => $thirdAt,
    ]);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => $this->user->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => '',
        'created_at' => $fourthAt,
        'updated_at' => $fourthAt,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('workspace.conversations.show', ['slug' => $this->workspaceSlug(), 'id' => $conversation->id]));

    $response->assertOk()
        ->assertJsonPath('conversation.id', $conversation->id)
        ->assertJsonPath('timeline.items.0.subtype', 'message:visitor:text')
        ->assertJsonPath('timeline.items.1.subtype', 'event:created')
        ->assertJsonPath('timeline.items.2.subtype', 'message:ai:text')
        ->assertJsonPath('timeline.items.3.subtype', 'message:teammate:text')
        ->assertJsonPath('timeline.items.0.type', 'message')
        ->assertJsonPath('timeline.items.0.role', MessageRole::Visitor->value)
        ->assertJsonPath('timeline.items.0.kind', MessageKind::Text->value)
        ->assertJsonPath('timeline.items.0.event_type', null)
        ->assertJsonPath('timeline.items.1.type', 'event')
        ->assertJsonPath('timeline.items.1.role', null)
        ->assertJsonPath('timeline.items.1.kind', null)
        ->assertJsonPath('timeline.items.1.event_type', ConversationEventType::Created->value)
        ->assertJsonPath('timeline.items.2.role', MessageRole::Ai->value)
        ->assertJsonPath('timeline.items.2.kind', MessageKind::Text->value)
        ->assertJsonPath('timeline.items.0.content', 'Hello there')
        ->assertJsonPath('timeline.items.1.event_display.summary', $this->user->name.'手动创建了此会话')
        ->assertJsonPath('timeline.items.1.event_display.tone', ConversationEventTone::Muted->value)
        ->assertJsonPath('timeline.items.2.content', 'I can help with that.')
        ->assertJsonPath('timeline.items.2.sender_name', 'AI')
        ->assertJsonPath('timeline.items.3.sender_name', $this->user->name)
        ->assertJsonPath('timeline.items.3.sender_avatar_url', 'https://example.com/operator.png')
        ->assertJsonPath('timeline.items.3.content', '');

    expect($response->json('timeline.items'))->toHaveCount(4)
        ->and($response->json('timeline.items.0.occurred_at'))->toBe($firstAt->toIso8601String())
        ->and($response->json('timeline.items.1.occurred_at'))->toBe($secondAt->toIso8601String())
        ->and($response->json('timeline.items.2.occurred_at'))->toBe($thirdAt->toIso8601String())
        ->and($response->json('timeline.items.3.occurred_at'))->toBe($fourthAt->toIso8601String());
});

test('会话详情游标分页时间线块正确', function () {
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $firstAt = CarbonImmutable::parse('2026-04-18 10:00:00');
    $secondAt = $firstAt->addMinutes(1);
    $thirdAt = $secondAt->addMinutes(1);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => null,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'Oldest',
        'created_at' => $firstAt,
        'updated_at' => $firstAt,
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'actor_user_id' => $this->user->id,
        'type' => ConversationEventType::AssignmentChanged,
        'payload' => ['source' => 'claim', 'user_id' => (string) $this->user->id],
        'created_at' => $secondAt,
    ]);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => $this->user->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'Newest',
        'created_at' => $thirdAt,
        'updated_at' => $thirdAt,
    ]);

    $action = app(ShowConversationDetailAction::class);

    $firstPage = $action->handle($conversation, null, 2);

    expect($firstPage->timeline->items)->toHaveCount(2)
        ->and($firstPage->timeline->items[0]->occurred_at)->toBe($secondAt->toIso8601String())
        ->and($firstPage->timeline->items[1]->occurred_at)->toBe($thirdAt->toIso8601String())
        ->and($firstPage->timeline->next_cursor)->not->toBeNull();

    $secondPage = $action->handle($conversation, $firstPage->timeline->next_cursor, 2);

    expect($secondPage->timeline->items)->toHaveCount(1)
        ->and($secondPage->timeline->items[0]->content)->toBe('Oldest')
        ->and($secondPage->timeline->items[0]->occurred_at)->toBe($firstAt->toIso8601String())
        ->and($secondPage->timeline->next_cursor)->toBeNull();
});

test('会话详情展示接待 agent 的客服可读事件', function () {
    app()->setLocale('zh_CN');

    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $baseAt = CarbonImmutable::parse('2026-04-18 12:00:00');

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionToolCalled,
        'payload' => ['tool' => 'dispatch_task', 'task_id' => 'task_1', 'question' => '查询订单状态'],
        'created_at' => $baseAt,
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionToolCalled,
        'payload' => ['tool' => 'cancel_task', 'task_id' => 'task_1', 'result' => 'cancelled'],
        'created_at' => $baseAt->addSecond(),
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionToolCalled,
        'payload' => ['tool' => 'handoff_to_human', 'reason' => HumanServiceUnavailableReason::OutsideBusinessHours->value, 'accepted' => false],
        'created_at' => $baseAt->addSeconds(2),
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionToolCalled,
        'payload' => ['tool' => 'handoff_to_human', 'reason' => HumanServiceUnavailableReason::NoOnlineTeammate->value, 'accepted' => false],
        'created_at' => $baseAt->addSeconds(3),
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionTurnEnded,
        'payload' => ['ended_by' => 'timeout'],
        'created_at' => $baseAt->addSeconds(4),
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionTurnEnded,
        'payload' => ['ended_by' => 'error'],
        'created_at' => $baseAt->addSeconds(5),
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionTurnEnded,
        'payload' => ['ended_by' => 'max_iterations'],
        'created_at' => $baseAt->addSeconds(6),
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionToolCalled,
        'payload' => ['tool' => 'dispatch_task', 'question' => '查询订单状态', 'result' => 'task_limit_exceeded'],
        'created_at' => $baseAt->addSeconds(7),
    ]);

    $detail = ShowConversationDetailAction::run($conversation);

    expect($detail->timeline->items)->toHaveCount(8)
        ->and($detail->timeline->items[0]->event_display->summary)->toBe('AI 发起了一个后台任务')
        ->and($detail->timeline->items[0]->event_display->tone)->toBe(ConversationEventTone::Muted)
        ->and($detail->timeline->items[1]->event_display->summary)->toBe('AI 取消了一个后台任务')
        ->and($detail->timeline->items[2]->event_display->summary)->toBe('当前非服务时间，AI 正在继续接待')
        ->and($detail->timeline->items[2]->event_display->tone)->toBe(ConversationEventTone::Muted)
        ->and($detail->timeline->items[3]->event_display->summary)->toBe('当前无客服在线，AI 正在继续接待')
        ->and($detail->timeline->items[3]->event_display->tone)->toBe(ConversationEventTone::Important)
        ->and($detail->timeline->items[4]->event_display->summary)->toBe('AI 响应超时，访客消息未得到回复')
        ->and($detail->timeline->items[4]->event_display->tone)->toBe(ConversationEventTone::Important)
        ->and($detail->timeline->items[5]->event_display->summary)->toBe('AI 接待过程中遇到异常，已中断')
        ->and($detail->timeline->items[5]->event_display->tone)->toBe(ConversationEventTone::Warning)
        ->and($detail->timeline->items[6]->event_display->summary)->toBe('AI 多轮尝试后未能解决，已中断')
        ->and($detail->timeline->items[6]->event_display->tone)->toBe(ConversationEventTone::Important)
        ->and($detail->timeline->items[7]->event_display->summary)->toBe('AI 无法发起更多后台任务')
        ->and($detail->timeline->items[7]->event_display->tone)->toBe(ConversationEventTone::Warning);
});

test('会话详情展示自动回复翻译失败事件', function () {
    app()->setLocale('zh_CN');

    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::AutoMessageTranslationFailed,
        'payload' => [
            'trigger' => ConversationAutoMessageTrigger::AiWelcome->value,
            'mode' => AutoMessageTranslationFailureMode::Skip->value,
            'content' => '您好，我是 AI 助手。',
        ],
    ]);

    $detail = ShowConversationDetailAction::run($conversation);
    $facts = array_map(
        fn ($fact): array => ['label' => $fact->label, 'value' => $fact->value],
        $detail->timeline->items[0]->event_display->facts,
    );

    expect($detail->timeline->items[0]->event_display->summary)->toBe('自动回复未发送：翻译不可用')
        ->and($detail->timeline->items[0]->event_display->detail)->toBe('您好，我是 AI 助手。')
        ->and($detail->timeline->items[0]->event_display->tone)->toBe(ConversationEventTone::Warning)
        ->and($facts)->toEqual([['label' => '自动回复', 'value' => 'AI 接待欢迎语']]);
});

test('会话详情事件展示用摘要承载客服需要的信息', function () {
    app()->setLocale('zh_CN');

    $previousUser = User::factory()->create(['name' => '李四']);
    $previousUser->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Admin->value]);

    $targetUser = User::factory()->create(['name' => '王五']);
    $targetUser->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Admin->value]);

    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $baseAt = CarbonImmutable::parse('2026-04-18 13:00:00');
    $events = [
        [
            'type' => ConversationEventType::HandoffRequested,
            'payload' => ['reason' => 'user_requested'],
            'summary' => '访客要求转人工',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::HandoffRequested,
            'payload' => ['reason' => 'ai_requested'],
            'summary' => 'AI 判断此会话需要人工处理',
            'tone' => ConversationEventTone::Important,
        ],
        [
            'type' => ConversationEventType::HandoffRequested,
            'payload' => ['reason' => 'low_confidence'],
            'summary' => 'AI 不确定如何回答，已转人工',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::HandoffRequested,
            'payload' => ['reason' => 'tool_failure'],
            'summary' => 'AI 处理时遇到异常，已转人工',
            'tone' => ConversationEventTone::Warning,
        ],
        [
            'type' => ConversationEventType::HandoffRequested,
            'payload' => ['reason' => 'policy_required'],
            'summary' => '按业务规则需人工处理',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::AssignmentChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['source' => 'claim', 'user_id' => (string) $this->user->id],
            'summary' => $this->user->name.'接管了此会话',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::AssignmentChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['source' => 'reply', 'user_id' => (string) $this->user->id],
            'summary' => $this->user->name.'回复并接管了此会话',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::AssignmentChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['source' => 'transfer_to_human', 'user_id' => (string) $this->user->id],
            'summary' => $this->user->name.'接手了 AI 正在处理的会话',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::AssignmentChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['source' => 'takeover', 'previous_user_id' => (string) $previousUser->id],
            'summary' => $this->user->name.'接替了李四处理此会话',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::AssignmentChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['source' => 'transfer_to_teammate', 'user_id' => (string) $targetUser->id],
            'summary' => $this->user->name.'将会话转交给了王五',
            'tone' => ConversationEventTone::Normal,
        ],
        [
            'type' => ConversationEventType::AssignmentChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['source' => 'release_to_ai'],
            'summary' => $this->user->name.'将会话交给了 AI',
            'tone' => ConversationEventTone::Muted,
        ],
        [
            'type' => ConversationEventType::StatusChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['status' => ConversationStatus::Closed->value],
            'summary' => $this->user->name.'关闭了此会话',
            'tone' => ConversationEventTone::Muted,
        ],
        [
            'type' => ConversationEventType::StatusChanged,
            'actor_user_id' => $this->user->id,
            'payload' => ['status' => ConversationStatus::Open->value],
            'summary' => $this->user->name.'重新打开了此会话',
            'tone' => ConversationEventTone::Normal,
        ],
    ];

    foreach ($events as $index => $event) {
        ConversationEvent::factory()->forConversation($conversation)->create([
            'actor_user_id' => $event['actor_user_id'] ?? null,
            'type' => $event['type'],
            'payload' => $event['payload'],
            'created_at' => $baseAt->addSeconds($index),
        ]);
    }

    $detail = ShowConversationDetailAction::run($conversation);

    expect($detail->timeline->items)->toHaveCount(count($events));

    foreach ($events as $index => $event) {
        expect($detail->timeline->items[$index]->event_display->summary)->toBe($event['summary'])
            ->and($detail->timeline->items[$index]->event_display->tone)->toBe($event['tone']);
    }
});

test('会话详情遇到未知事件来源时显性失败', function () {
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'actor_user_id' => $this->user->id,
        'type' => ConversationEventType::AssignmentChanged,
        'payload' => ['source' => 'unexpected_source'],
    ]);

    expect(fn () => ShowConversationDetailAction::run($conversation))
        ->toThrow(RuntimeException::class, 'Unknown assignment_changed source');
});

test('会话消息拒绝无效角色类型组合', function () {
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    expect(function () use ($conversation): void {
        ConversationMessage::factory()->forConversation($conversation)->create([
            'sender_user_id' => null,
            'role' => MessageRole::Visitor,
            'kind' => MessageKind::ToolCall,
            'content' => null,
            'payload' => ['tool' => 'search_docs'],
        ]);
    })->toThrow(ValidationException::class);
});
