<?php

use App\Actions\Native\Reception\AppendAiMessageBridgeAction;
use App\Actions\Native\Reception\HandleAiUnavailableBridgeAction;
use App\Actions\Native\Reception\LoadConversationHistoryBridgeAction;
use App\Actions\Native\Reception\LoadReceptionRuntimeBridgeAction;
use App\Actions\Native\Reception\LogReceptionEventBridgeAction;
use App\Actions\Native\Reception\RequestHandoffBridgeAction;
use App\Actions\Native\Reception\StartOrResumeReceptionSessionBridgeAction;
use App\Actions\Reception\AppendAiMessageAction;
use App\Actions\Reception\AppendVisitorMessageAction;
use App\Enums\ConversationEntryMode;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Enums\Reception\ReceptionRoutingMode;
use App\Enums\UserOnlineStatus;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
    ]);
});

test('LoadReceptionRuntimeBridgeAction 在 AI 接待时返回 system prompt 与主模型', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['available'])->toBeTrue()
        ->and($payload['inbox_status'])->toBe(ConversationInboxStatus::AiHandling->value)
        ->and($payload['conversation_id'])->toBe($started->conversation_id)
        ->and($payload['plan_version_id'])->toBe((string) $channel->reception_plan_version_id)
        ->and($payload['system_prompt'])->toBeString()
        ->and($payload['system_prompt'])->toContain('[人工服务状态]')
        ->and($payload['quote_visitor_message_enabled'])->toBeFalse()
        ->and($payload['primary_model']['provider']['slug'])->not->toBeEmpty()
        ->and($payload['primary_model']['provider']['protocol'])->toBe('openai')
        ->and($payload['primary_model']['provider']['credentials']['key'])->toBe('test-key')
        ->and($payload['primary_model']['model']['model_id'])->toBe('gpt-reception')
        ->and($payload['primary_task_model']['model']['model_id'])->toBe('gpt-reception')
        ->and($payload['task_model_candidates'])->toHaveCount(1);
});

test('LoadReceptionRuntimeBridgeAction 返回任务智能体配置的模型', function () {
    $systemContext = SystemContext::factory()->create();

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'provider-'.Str::lower(Str::random(6)),
        'name' => 'Test Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $receptionModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Reception',
        'model_id' => 'gpt-reception',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $taskModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Task',
        'model_id' => 'gpt-task',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 1,
    ]);
    $taskBackup = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Task Backup',
        'model_id' => 'gpt-task-backup',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 2,
    ]);

    $plan = ReceptionPlan::factory()->create();
    $baseSnapshot = ReceptionPlanVersion::factory()->definition()['snapshot_config'] ?? [];
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->create([
            'snapshot_config' => array_replace_recursive($baseSnapshot, [
                'reception_config' => [
                    'default_model' => ['ai_model_id' => $receptionModel->id],
                ],
                'task_config' => [
                    'default_model' => ['ai_model_id' => $taskModel->id],
                    'model_candidates' => [
                        ['ai_model_id' => $taskModel->id, 'priority' => 0],
                        ['ai_model_id' => $taskBackup->id, 'priority' => 1],
                    ],
                ],
                'strategy_config' => nativeRuntimeStrategyConfig([]),
                'auto_messages_config' => nativeRuntimeDisabledAutoMessagesConfig(),
            ]),
            'compiled_config' => [
                'reception_agent' => ['instruction' => '测试指令'],
                'reception_config' => [
                    'default_model' => ['ai_model_id' => $receptionModel->id],
                ],
                'task_config' => [
                    'default_model' => ['ai_model_id' => $taskModel->id],
                    'model_candidates' => [
                        ['ai_model_id' => $taskModel->id, 'priority' => 0],
                        ['ai_model_id' => $taskBackup->id, 'priority' => 1],
                    ],
                ],
                'service_scenarios' => [],
                'knowledge_bases' => [],
                'mcp_tools' => [],
            ],
        ]);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['available'])->toBeTrue()
        ->and($payload['primary_model']['model']['model_id'])->toBe('gpt-reception')
        ->and($payload['primary_task_model']['model']['model_id'])->toBe('gpt-task')
        ->and($payload['task_model_candidates'])->toHaveCount(2)
        ->and($payload['task_model_candidates'][0]['model']['model_id'])->toBe('gpt-task')
        ->and($payload['task_model_candidates'][1]['model']['model_id'])->toBe('gpt-task-backup');
});

test('LoadReceptionRuntimeBridgeAction 返回接待方案 AI 引用访客消息开关', function () {
    $channel = makeNativeRuntimeChannel([
        'quote_visitor_message_enabled' => false,
    ]);

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['available'])->toBeTrue()
        ->and($payload['quote_visitor_message_enabled'])->toBeFalse();
});

test('重点客户默认会获得谨慎接待运行时提示', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );
    $conversation = Conversation::query()->with('contact')->findOrFail($started->conversation_id);
    $conversation->contact->forceFill([
        'is_important' => true,
        'important_at' => now(),
        'important_source' => 'manual',
    ])->save();

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['system_prompt'])
        ->toContain('[重点客户接待要求]')
        ->toContain('回复时保持更高谨慎度')
        ->toContain('优先调用 handoff_to_human')
        ->toContain('不要主动告知访客其重点客户标记。');
});

test('关闭重点客户 AI 提示开关时不追加重点客户运行时提示', function () {
    $channel = makeNativeRuntimeChannel([
        'important_contact_ai_careful_reply_enabled' => false,
        'important_contact_ai_handoff_hint_enabled' => false,
    ]);
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );
    $conversation = Conversation::query()->with('contact')->findOrFail($started->conversation_id);
    $conversation->contact->forceFill([
        'is_important' => true,
        'important_at' => now(),
        'important_source' => 'manual',
    ])->save();

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['system_prompt'])->not->toContain('[重点客户接待要求]');
});

test('重点客户人工在线时优先进入人工待接队列', function () {
    $channel = makeNativeRuntimeChannel([
        'important_contact_human_first_when_online_enabled' => true,
    ]);
    $token = Str::lower(Str::random(32));
    $contact = Contact::factory()->create([
        'is_important' => true,
        'important_at' => now(),
        'important_source' => 'manual',
    ]);
    ContactIdentity::factory()->session($token)->create([
        'contact_id' => $contact->id,
    ]);

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        $token,
        ConversationEntryMode::Standalone->value,
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);

    expect($conversation->contact_id)->toBe($contact->id)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);
});

test('LoadReceptionRuntimeBridgeAction 按访客语言返回人工服务状态提示', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );
    Conversation::query()->whereKey($started->conversation_id)->update(['visitor_locale' => 'en']);

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['system_prompt'])->toContain('[Human Service Status]')
        ->and($payload['system_prompt'])->toContain('Always reply to the visitor in English')
        ->and($payload['system_prompt'])->toContain('Human support business hours');
});

test('LoadReceptionRuntimeBridgeAction 在客服接管时返回 available=false', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    Conversation::query()
        ->whereKey($started->conversation_id)
        ->update(['inbox_status' => ConversationInboxStatus::TeammateHandling]);

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['available'])->toBeFalse()
        ->and($payload['reason'])->toBe('taken_over');
});

test('LoadReceptionRuntimeBridgeAction 在 plan version 缺失时返回 no_plan', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    Conversation::query()
        ->whereKey($started->conversation_id)
        ->update(['reception_plan_version_id' => null]);

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['available'])->toBeFalse()
        ->and($payload['reason'])->toBe('no_plan');
});

test('RequestHandoffBridgeAction 在人工可用时把会话翻成 TeammatePending 并记录事件', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );
    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        '我要转人工',
    );
    $visitorMessage = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Visitor)
        ->firstOrFail();

    $decision = app(RequestHandoffBridgeAction::class)->handle(
        $started->conversation_id,
        'tool_failure',
        (string) $visitorMessage->id,
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);

    expect($decision->accepted)->toBeTrue()
        ->and($decision->reason)->toBe('tool_failure')
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending)
        ->and((string) ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Ai)
            ->where('content', '已为您转接人工客服，请稍等。')
            ->firstOrFail()->quoted_message_id)->toBe((string) $visitorMessage->id);

    $event = ConversationEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('type', ConversationEventType::HandoffRequested)
        ->firstOrFail();

    expect($event->payload['reason'])->toBe('tool_failure')
        ->and($event->payload['actor_kind'])->toBe('ai');
});

test('RequestHandoffBridgeAction 在无人在线时拒绝转人工并保持 AI 接待', function () {
    $channel = makeNativeRuntimeChannel();
    User::query()->update(['online_status' => UserOnlineStatus::Offline->value]);
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );
    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        '帮我转人工',
    );
    $visitorMessage = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Visitor)
        ->firstOrFail();

    $decision = app(RequestHandoffBridgeAction::class)->handle(
        $started->conversation_id,
        'user_requested',
        (string) $visitorMessage->id,
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reason)->toBe('no_online_teammate')
        ->and($decision->notice)->not->toBeEmpty()
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling)
        ->and($conversation->waiting_for_visitor_reply)->toBeTrue()
        ->and((string) ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Ai)
            ->where('content', '当前暂无法转接人工，我会继续为您处理。')
            ->firstOrFail()->quoted_message_id)->toBe((string) $visitorMessage->id)
        ->and(ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', ConversationEventType::HandoffRequested)->exists())->toBeFalse();
});

test('RequestHandoffBridgeAction 在非营业时间拒绝转人工并返回营业时间提示', function () {
    $schedule = [];
    for ($day = 1; $day <= 7; $day++) {
        $schedule[] = ['day' => $day, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'];
    }
    $channel = makeNativeRuntimeChannel([
        'business_hours' => [
            'timezone' => 'UTC',
            'outside_hours_notice' => '当前非人工服务时间，AI 将继续为您服务。',
            'schedule' => $schedule,
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $decision = app(RequestHandoffBridgeAction::class)->handle(
        $started->conversation_id,
        'user_requested',
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reason)->toBe('outside_business_hours')
        ->and($decision->notice)->toBe('当前非人工服务时间，AI 将继续为您服务。')
        ->and($decision->business_hours_summary)->toContain('周一 休息')
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling)
        ->and(ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Ai)
            ->where('content', '当前非人工服务时间，AI 将继续为您服务。')
            ->exists())->toBeTrue();
});

test('RequestHandoffBridgeAction 按访客语言返回人工服务摘要', function () {
    $schedule = [];
    for ($day = 1; $day <= 7; $day++) {
        $schedule[] = ['day' => $day, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'];
    }
    $channel = makeNativeRuntimeChannel([
        'business_hours' => [
            'timezone' => 'UTC',
            'outside_hours_notice' => '当前非人工服务时间，AI 将继续为您服务。',
            'schedule' => $schedule,
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );
    Conversation::query()->whereKey($started->conversation_id)->update(['visitor_locale' => 'en']);

    $decision = app(RequestHandoffBridgeAction::class)->handle(
        $started->conversation_id,
        'user_requested',
    );

    expect($decision->accepted)->toBeFalse()
        ->and($decision->business_hours_summary)->toContain('Mon Closed');
});

test('LogReceptionEventBridgeAction 写入三种接待事件并落库 payload', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $bridge = app(LogReceptionEventBridgeAction::class);
    $bridge->handle($started->conversation_id, 'reception_turn_started', ['event_kind' => 'visitor_message']);
    $bridge->handle($started->conversation_id, 'reception_tool_called', ['tool' => 'dispatch_task', 'question' => '查询订单状态']);
    $bridge->handle($started->conversation_id, 'reception_turn_ended', ['ended_by' => 'tool_done']);

    $events = ConversationEvent::query()
        ->where('conversation_id', $started->conversation_id)
        ->whereIn('type', [
            ConversationEventType::ReceptionTurnStarted,
            ConversationEventType::ReceptionToolCalled,
            ConversationEventType::ReceptionTurnEnded,
        ])
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(3)
        ->and($events[0]->payload['event_kind'])->toBe('visitor_message')
        ->and($events[1]->payload['tool'])->toBe('dispatch_task')
        ->and($events[2]->payload['ended_by'])->toBe('tool_done');
});

test('AppendAi bridge 接受 quoted_message_id 并把它写到 AI 消息上', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        '我想查 12345',
    );
    $visitorMessage = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Visitor)
        ->firstOrFail();

    app(AppendAiMessageBridgeAction::class)->handle(
        $started->conversation_id,
        '您的订单已发货',
        (string) $visitorMessage->id,
    );

    $aiMessage = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Ai)
        ->firstOrFail();

    expect((string) $aiMessage->quoted_message_id)->toBe((string) $visitorMessage->id);
});

test('AppendAi bridge 在 quoted_message_id 非法时静默丢弃引用', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    app(AppendAiMessageBridgeAction::class)->handle(
        $started->conversation_id,
        '稍等',
        '01jzzzzzzzzzzzzzzzzzzzzzzz',
    );

    $aiMessage = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Ai)
        ->firstOrFail();

    expect($aiMessage->quoted_message_id)->toBeNull();
});

test('LoadConversationHistoryBridgeAction 按 seq_no 升序返回 visitor、ai 与 teammate 文本消息，过滤撤回和非文本', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );
    $conversationId = $started->conversation_id;

    // 顺序：visitor → AI → visitor → 已撤回的 visitor → 非文本 visitor → teammate
    app(AppendVisitorMessageAction::class)->handle($channel->code, $started->session_token, '你好');
    app(AppendAiMessageAction::class)->handle(
        Conversation::query()->findOrFail($conversationId),
        '请问需要什么帮助？',
    );
    app(AppendVisitorMessageAction::class)->handle($channel->code, $started->session_token, '查订单 12345');

    $recalled = ConversationMessage::query()->create([
        'conversation_id' => $conversationId,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '这条已撤回',
        'recalled_at' => now(),
    ]);
    expect($recalled->fresh()->recalled_at)->not->toBeNull();

    ConversationMessage::query()->create([
        'conversation_id' => $conversationId,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Image,
        'content' => null,
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversationId,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => '客服路过',
    ]);

    $rows = app(LoadConversationHistoryBridgeAction::class)->handle($conversationId, null);

    expect($rows)->toHaveCount(4)
        ->and($rows[0]['role'])->toBe('visitor')
        ->and($rows[0]['content'])->toBe('你好')
        ->and($rows[1]['role'])->toBe('ai')
        ->and($rows[1]['content'])->toBe('请问需要什么帮助？')
        ->and($rows[2]['role'])->toBe('visitor')
        ->and($rows[2]['content'])->toBe('查订单 12345')
        ->and($rows[3]['role'])->toBe('teammate')
        ->and($rows[3]['content'])->toBe('客服路过')
        ->and($rows[0]['id'])->not->toBeEmpty()
        ->and($rows[1]['id'])->not->toBeEmpty()
        ->and($rows[2]['id'])->not->toBeEmpty()
        ->and($rows[3]['id'])->not->toBeEmpty();
});

test('AppendAi 与 Handoff bridge 始终作用在原会话，不会因为缺 user_token 而误开新会话', function () {
    $channel = makeNativeRuntimeChannel();

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $originalConversationId = $started->conversation_id;
    expect(Conversation::query()->count())->toBe(1);

    app(AppendAiMessageBridgeAction::class)->handle(
        $originalConversationId,
        '稍等，我帮您查',
    );

    expect(Conversation::query()->count())->toBe(1)
        ->and(ConversationMessage::query()->where('conversation_id', $originalConversationId)->where('role', MessageRole::Ai)->count())->toBe(1);

    $decision = app(RequestHandoffBridgeAction::class)->handle(
        $originalConversationId,
        'tool_failure',
    );

    expect($decision->accepted)->toBeTrue()
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Conversation::query()->findOrFail($originalConversationId)->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);
});

test('LogReceptionEventBridgeAction 拒绝未知 type', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    expect(fn () => app(LogReceptionEventBridgeAction::class)->handle(
        $started->conversation_id,
        'unknown_event',
        null,
    ))->toThrow(ValidationException::class);
});

test('LoadReceptionRuntimeBridgeAction 返回完整模型候选列表和 AI 不可用兜底文案', function () {
    $systemContext = SystemContext::factory()->create();

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'provider-'.Str::lower(Str::random(6)),
        'name' => 'Test Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'primary-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $primary = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Primary',
        'model_id' => 'gpt-primary',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $backup = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Backup',
        'model_id' => 'gpt-backup',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 1,
    ]);

    $plan = ReceptionPlan::factory()->create();
    $baseSnapshot = ReceptionPlanVersion::factory()->definition()['snapshot_config'] ?? [];
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel($primary->id)
        ->create([
            'snapshot_config' => array_replace_recursive($baseSnapshot, [
                'reception_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                    'model_candidates' => [
                        ['ai_model_id' => $primary->id, 'priority' => 0],
                        ['ai_model_id' => $backup->id, 'priority' => 1],
                    ],
                ],
                'task_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                ],
                'strategy_config' => nativeRuntimeStrategyConfig([
                    'ai_unavailable_notice' => '自定义兜底文案',
                ]),
                'auto_messages_config' => nativeRuntimeDisabledAutoMessagesConfig(),
            ]),
            'compiled_config' => [
                'reception_agent' => ['instruction' => '测试指令'],
                'reception_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                    'model_candidates' => [
                        ['ai_model_id' => $primary->id, 'priority' => 0],
                        ['ai_model_id' => $backup->id, 'priority' => 1],
                    ],
                ],
                'task_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                ],
                'service_scenarios' => [],
                'knowledge_bases' => [],
                'mcp_tools' => [],
            ],
        ]);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['available'])->toBeTrue()
        ->and($payload['model_candidates'])->toHaveCount(2)
        ->and($payload['model_candidates'][0]['model']['model_id'])->toBe('gpt-primary')
        ->and($payload['model_candidates'][1]['model']['model_id'])->toBe('gpt-backup')
        ->and($payload['primary_model']['model']['model_id'])->toBe('gpt-primary')
        ->and($payload['ai_unavailable_notice'])->toBe('自定义兜底文案');
});

test('LoadReceptionRuntimeBridgeAction 过滤已停用的备用模型', function () {
    $systemContext = SystemContext::factory()->create();

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'provider-'.Str::lower(Str::random(6)),
        'name' => 'Test Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $primary = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Primary',
        'model_id' => 'gpt-primary',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $disabled = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Disabled',
        'model_id' => 'gpt-disabled',
        'type' => 'llm',
        'is_active' => false,
        'is_builtin' => false,
        'sort_order' => 1,
    ]);

    $plan = ReceptionPlan::factory()->create();
    $baseSnapshot = ReceptionPlanVersion::factory()->definition()['snapshot_config'] ?? [];
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel($primary->id)
        ->create([
            'snapshot_config' => array_replace_recursive($baseSnapshot, [
                'reception_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                ],
                'task_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                ],
                'strategy_config' => nativeRuntimeStrategyConfig([]),
                'auto_messages_config' => nativeRuntimeDisabledAutoMessagesConfig(),
            ]),
            'compiled_config' => [
                'reception_agent' => ['instruction' => '测试指令'],
                'reception_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                    'model_candidates' => [
                        ['ai_model_id' => $primary->id, 'priority' => 0],
                        ['ai_model_id' => $disabled->id, 'priority' => 1],
                    ],
                ],
                'task_config' => [
                    'default_model' => ['ai_model_id' => $primary->id],
                ],
                'service_scenarios' => [],
                'knowledge_bases' => [],
                'mcp_tools' => [],
            ],
        ]);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $payload = app(LoadReceptionRuntimeBridgeAction::class)->handle($started->conversation_id);

    expect($payload['available'])->toBeTrue()
        ->and($payload['model_candidates'])->toHaveCount(1)
        ->and($payload['model_candidates'][0]['model']['model_id'])->toBe('gpt-primary');
});

test('HandleAiUnavailableBridgeAction 发送兜底文案并将会话转为人工待接', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $result = app(HandleAiUnavailableBridgeAction::class)->handle(
        $started->conversation_id,
        '测试兜底文案',
    );

    expect($result['handled'])->toBeTrue();

    $conversation = Conversation::query()->find($started->conversation_id);
    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);

    $message = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Ai)
        ->where('content', '测试兜底文案')
        ->first();
    expect($message)->not->toBeNull()
        ->and($message->kind)->toBe(MessageKind::Text);

    $event = ConversationEvent::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('type', ConversationEventType::HandoffRequested)
        ->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['reason'])->toBe('ai_unavailable')
        ->and($event->payload['actor_kind'])->toBe('system');
});

test('HandleAiUnavailableBridgeAction 在非 AI 接待状态时返回 handled=false', function () {
    $channel = makeNativeRuntimeChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    Conversation::query()
        ->whereKey($started->conversation_id)
        ->update(['inbox_status' => ConversationInboxStatus::TeammateHandling]);

    $result = app(HandleAiUnavailableBridgeAction::class)->handle(
        $started->conversation_id,
        '兜底文案',
    );

    expect($result['handled'])->toBeFalse();
});

/**
 * 建一个绑定了已发布版本与有效 AI 模型的渠道，便于运行时桥接测试拿到完整 compiled_config。
 *
 * @param  array<string, mixed>  $strategyOverrides
 */
function makeNativeRuntimeChannel(array $strategyOverrides = []): Channel
{
    $systemContext = SystemContext::factory()->create();
    User::factory()->create([
        'is_super_admin' => true,
        'online_status' => UserOnlineStatus::Online->value,
    ]);

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'reception-provider-'.Str::lower(Str::random(6)),
        'name' => 'Reception Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $model = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Reception Model',
        'model_id' => 'gpt-reception',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $plan = ReceptionPlan::factory()->create([
        'name' => '接待方案-'.Str::lower(Str::random(6)),
    ]);
    $baseSnapshot = ReceptionPlanVersion::factory()->definition()['snapshot_config'] ?? [];
    $baseSnapshot['auto_messages_config'] = nativeRuntimeDisabledAutoMessagesConfig();
    $baseSnapshot['reception_config'] = array_merge(
        $baseSnapshot['reception_config'] ?? [],
        ['default_model' => ['ai_model_id' => $model->id]],
    );
    $baseSnapshot['task_config'] = array_merge(
        $baseSnapshot['task_config'] ?? [],
        ['default_model' => ['ai_model_id' => $model->id]],
    );
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel($model->id)
        ->create([
            'snapshot_config' => array_replace_recursive($baseSnapshot, [
                'strategy_config' => nativeRuntimeStrategyConfig($strategyOverrides),
            ]),
        ]);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);

    return $channel;
}

/**
 * 构造不参与自动回复断言的接待方案快照配置。
 *
 * @return array<string, array{enabled: bool, message: string|null}>
 */
function nativeRuntimeDisabledAutoMessagesConfig(): array
{
    return [
        'ai_welcome' => ['enabled' => false, 'message' => null],
        'teammate_joined' => ['enabled' => false, 'message' => null],
        'teammate_transferred' => ['enabled' => false, 'message' => null],
    ];
}

/**
 * 构造运行时测试使用的接待策略快照。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nativeRuntimeStrategyConfig(array $overrides): array
{
    return array_replace_recursive([
        'reception_mode' => ReceptionRoutingMode::AiFirst->value,
        'unassigned_ai_takeover_enabled' => false,
        'unassigned_ai_takeover_timeout_seconds' => 120,
        'teammate_no_response_ai_takeover_enabled' => true,
        'teammate_no_response_ai_takeover_timeout_seconds' => 300,
        'important_contact_ai_careful_reply_enabled' => true,
        'important_contact_ai_handoff_hint_enabled' => true,
        'important_contact_human_first_when_online_enabled' => false,
        'quote_visitor_message_enabled' => false,
        'handoff_available_notice' => '已为您转接人工客服，请稍等。',
        'handoff_no_teammate_notice' => '当前暂无法转接人工，我会继续为您处理。',
        'ai_unavailable_notice' => '很抱歉，AI 助手暂时无法为您服务，正在为您转接人工客服，请稍候。',
        'business_hours' => null,
    ], $overrides);
}
