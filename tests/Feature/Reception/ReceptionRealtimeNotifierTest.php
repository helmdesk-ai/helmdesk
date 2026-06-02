<?php

use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Services\Realtime\MercurePublisher;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Reception\ReceptionMercureTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('它发布收件箱信号和访客会话快照', function () {
    $contact = Contact::factory()->visitor()->create([
    ]);
    $plan = ReceptionPlan::factory()->create();
    $version = ReceptionPlanVersion::factory()->for($plan, 'plan')->create([
        'snapshot_config' => [
            'persona_config' => ['display_name' => 'Desk Bot', 'tone' => 'concise'],
            'strategy_config' => [
                'reception_mode' => 'ai_first',
                'unassigned_ai_takeover_enabled' => false,
                'unassigned_ai_takeover_timeout_seconds' => 120,
                'teammate_no_response_ai_takeover_enabled' => true,
                'teammate_no_response_ai_takeover_timeout_seconds' => 300,
                'important_contact_ai_careful_reply_enabled' => true,
                'important_contact_ai_handoff_hint_enabled' => true,
                'important_contact_human_first_when_online_enabled' => false,
                'quote_visitor_message_enabled' => true,
                'handoff_available_notice' => '已为您转接人工客服，请稍等。',
                'handoff_no_teammate_notice' => '当前暂无法转接人工，我会继续为您处理。',
                'ai_unavailable_notice' => '很抱歉，AI 助手暂时无法为您服务，正在为您转接人工客服，请稍候。',
                'business_hours' => null,
            ],
            'auto_messages_config' => [
                'ai_welcome' => ['enabled' => false, 'message' => null],
                'teammate_joined' => ['enabled' => false, 'message' => null],
                'teammate_transferred' => ['enabled' => false, 'message' => null],
            ],
        ],
    ]);
    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $version->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
            'waiting_for_visitor_reply' => true,
        ]);
    ContactIdentity::factory()->session(str_repeat('a', 32))->create([
        'contact_id' => $contact->id,
    ]);
    ConversationMessage::factory()->visitorText()->forConversation($conversation)->create([
        'content' => '我需要帮助',
    ]);

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
    ]);

    app(ReceptionRealtimeNotifier::class)->conversationChanged($conversation, 'visitor_message_created');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'http://go-runtime.test/_helmdesk/internal/realtime/publish'
        && $request->header('X-Helmdesk-Bridge-Token')[0] === 'bridge-token'
        && $request['topics'] === [ReceptionMercureTopics::inbox()]
        && $request['type'] === 'reception'
        && $request['data']['event'] === 'visitor_message_created'
        && $request['data']['conversation_id'] === $conversation->id
        && ! array_key_exists('state', $request['data']));

    Http::assertSent(fn ($request): bool => $request['topics'] === [ReceptionMercureTopics::conversation($conversation->id)]
        && $request['type'] === 'reception'
        && $request['data']['event'] === 'visitor_message_created'
        && $request['data']['conversation_id'] === $conversation->id
        && array_key_exists('occurred_at', $request['data'])
        && $request['data']['state']['conversation_id'] === $conversation->id
        && $request['data']['state']['assistant_name'] === 'Desk Bot'
        && $request['data']['state']['messages'][0]['content'] === '我需要帮助'
        // 安全契约：visitor topic 只包含访客端渲染会话所需字段。
        && ! array_key_exists('assigned_user_id', $request['data'])
        && ! array_key_exists('status', $request['data'])
        && ! array_key_exists('inbox_status', $request['data'])
        && ! array_key_exists('last_message_preview', $request['data'])
        && ! array_key_exists('contact_name', $request['data'])
        && ! array_key_exists('channel_name', $request['data'])
        && ! array_key_exists('session_token', $request['data']['state'])
        && ! array_key_exists('inbox_status', $request['data']['state'])
        && ! array_key_exists('inbox_status_label', $request['data']['state'])
        && ! array_key_exists('waiting_for_visitor_reply', $request['data']['state'])
        && ! array_key_exists('waiting_for_visitor_reply_label', $request['data']['state']));
});

test('Mercure发布失败时只记日志不抛异常以免阻断业务事务', function () {
    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response([
            'success' => false,
            'message' => 'publish failed',
        ], 500),
    ]);

    Log::spy();

    app(MercurePublisher::class)->publish('urn:test', 'reception', ['event' => 'test']);

    Log::shouldHaveReceived('warning')
        ->with('Mercure publish bridge returned an unsuccessful response.', Mockery::on(fn ($context) => $context['status'] === 500))
        ->once();
});
