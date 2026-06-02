<?php

use App\Actions\Conversation\ShowConversationListAction;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\ConversationVisitorReplyStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
    $this->runList = function (array $overrides = []): array {
        $props = ShowConversationListAction::run(...array_merge([
            'systemContext' => $this->systemContext,
            'currentUserId' => $this->user->id,
        ], $overrides));

        return collect($props->conversation_list)->pluck('id')->all();
    };
});

test('会话列表回退到created_at当last_message_at为null时', function () {
    $now = now();

    $newerWithMessage = Conversation::factory()->create([
        'created_at' => $now->copy()->subDays(5),
        'last_message_at' => $now->copy()->subHours(2),
    ]);

    $nullButFreshCreated = Conversation::factory()->create([
        'created_at' => $now->copy()->subHours(1),
        'last_message_at' => null,
        'last_message_preview' => null,
    ]);

    $olderWithMessage = Conversation::factory()->create([
        'created_at' => $now->copy()->subDays(10),
        'last_message_at' => $now->copy()->subDays(3),
    ]);

    expect(($this->runList)())->toEqual([
        $nullButFreshCreated->id,
        $newerWithMessage->id,
        $olderWithMessage->id,
    ]);
});

test('会话列表使用ID作为平局排序键当时间戳相同时', function () {
    $sameMoment = now()->subHour();

    $first = Conversation::factory()->create([
        'created_at' => $sameMoment,
        'last_message_at' => $sameMoment,
    ]);

    $second = Conversation::factory()->create([
        'created_at' => $sameMoment,
        'last_message_at' => $sameMoment,
    ]);

    $expectedOrder = collect([$first->id, $second->id])->sortDesc()->values()->all();

    expect(($this->runList)())->toEqual($expectedOrder);
});

test('会话列表筛选按状态inbox_status和访客回复状态独立', function () {
    $openHandling = Conversation::factory()->create([
        'status' => ConversationStatus::Open,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ]);
    $openPending = Conversation::factory()->create([
        'status' => ConversationStatus::Open,
        'inbox_status' => ConversationInboxStatus::TeammatePending,
    ]);
    $closed = Conversation::factory()->create([
        'status' => ConversationStatus::Closed,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ]);
    $waitingForVisitor = Conversation::factory()->create([
        'status' => ConversationStatus::Open,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
        'waiting_for_visitor_reply' => true,
    ]);

    expect(($this->runList)(['status' => ConversationStatus::Open]))
        ->toEqualCanonicalizing([$openHandling->id, $openPending->id, $waitingForVisitor->id])
        ->and(($this->runList)(['status' => ConversationStatus::Closed]))
        ->toEqual([$closed->id])
        ->and(($this->runList)(['inboxStatus' => ConversationInboxStatus::TeammateHandling]))
        ->toEqualCanonicalizing([$openHandling->id, $closed->id, $waitingForVisitor->id])
        ->and(($this->runList)(['visitorReplyStatus' => ConversationVisitorReplyStatus::Waiting]))
        ->toEqual([$waitingForVisitor->id])
        ->and(($this->runList)(['visitorReplyStatus' => ConversationVisitorReplyStatus::NotWaiting]))
        ->toEqualCanonicalizing([$openHandling->id, $openPending->id, $closed->id]);
});

test('会话列表序列化当前枚举筛选作为标量值', function () {
    $props = ShowConversationListAction::run(
        systemContext: $this->systemContext,
        status: ConversationStatus::Open,
        inboxStatus: ConversationInboxStatus::TeammateHandling,
        visitorReplyStatus: ConversationVisitorReplyStatus::Waiting,
        currentUserId: $this->user->id,
    )->toArray();

    expect($props['current_status'])
        ->toBe(ConversationStatus::Open->value)
        ->and($props['current_inbox_status'])
        ->toBe(ConversationInboxStatus::TeammateHandling->value)
        ->and($props['current_visitor_reply_status'])
        ->toBe(ConversationVisitorReplyStatus::Waiting->value);
});

test('会话列表拒绝无效枚举筛选查询', function () {
    $this->actingAs($this->user)
        ->from(route('admin.conversations.index'))
        ->get(route('admin.conversations.index', ['status' => 'unknown',
        ]))
        ->assertRedirect(route('admin.conversations.index'))
        ->assertSessionHasErrors('status');
});

test('会话列表筛选按接待方案', function () {
    $planA = ReceptionPlan::factory()->create([
        'name' => '接待方案-'.Str::lower(Str::random(6)),
    ]);
    $versionA = ReceptionPlanVersion::factory()->for($planA, 'plan')->create();

    $planB = ReceptionPlan::factory()->create([
        'name' => '接待方案-'.Str::lower(Str::random(6)),
    ]);
    $versionB = ReceptionPlanVersion::factory()->for($planB, 'plan')->create();

    $matching = Conversation::factory()->create([
        'reception_plan_version_id' => $versionA->id,
    ]);
    Conversation::factory()->create([
        'reception_plan_version_id' => $versionB->id,
    ]);
    Conversation::factory()->create([
        'reception_plan_version_id' => null,
    ]);

    expect(($this->runList)(['receptionPlanId' => $planA->id]))->toEqual([$matching->id]);
});

test('会话列表搜索匹配主题摘要预览联系人字段和消息内容', function () {
    $contact = Contact::factory()->create([
        'name' => 'Alice Example',
        'primary_email' => 'alice@example.com',
        'primary_phone' => '+1-555-0100',
    ]);

    $bySubject = Conversation::factory()->create([
        'subject' => 'needle-subject appears here',
        'summary' => null,
        'last_message_preview' => null,
    ]);
    $bySummary = Conversation::factory()->create([
        'subject' => null,
        'summary' => 'body text contains needle-summary token',
        'last_message_preview' => null,
    ]);
    $byPreview = Conversation::factory()->create([
        'subject' => null,
        'summary' => null,
        'last_message_preview' => 'latest message needle-preview sample',
    ]);
    $byContactName = Conversation::factory()->forContact($contact)->create([
        'subject' => null,
        'summary' => null,
        'last_message_preview' => null,
    ]);
    $byEmail = Conversation::factory()->forContact(Contact::factory()->create([
        'name' => null,
        'primary_email' => 'bob@acme.com',
        'primary_phone' => null,
    ]))->create([]);
    $byPhone = Conversation::factory()->forContact(Contact::factory()->create([
        'name' => null,
        'primary_email' => null,
        'primary_phone' => '+1-555-9999',
    ]))->create([]);

    $byMessageContent = Conversation::factory()->create([
        'subject' => null,
        'summary' => null,
        'last_message_preview' => null,
    ]);
    ConversationMessage::factory()->forConversation($byMessageContent)->create([
        'sender_user_id' => null,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'body includes needle-message secretly',
    ]);

    expect(($this->runList)(['search' => 'needle-subject']))->toEqual([$bySubject->id])
        ->and(($this->runList)(['search' => 'needle-summary']))->toEqual([$bySummary->id])
        ->and(($this->runList)(['search' => 'needle-preview']))->toEqual([$byPreview->id])
        ->and(($this->runList)(['search' => 'Alice Example']))->toEqual([$byContactName->id])
        ->and(($this->runList)(['search' => 'bob@acme.com']))->toEqual([$byEmail->id])
        ->and(($this->runList)(['search' => '+1-555-9999']))->toEqual([$byPhone->id])
        ->and(($this->runList)(['search' => 'needle-message']))->toEqual([$byMessageContent->id]);
});

test('会话搜索不丢弃会话当某个线程有大量匹配消息时', function () {
    $spammy = Conversation::factory()->create([
        'subject' => null,
        'summary' => null,
        'last_message_preview' => null,
    ]);

    foreach (range(1, 20) as $index) {
        ConversationMessage::factory()->forConversation($spammy)->create([
            'sender_user_id' => null,
            'role' => MessageRole::Visitor,
            'kind' => MessageKind::Text,
            'content' => "needle-crowd occurrence #{$index} in spammy thread",
        ]);
    }

    $quiet = Conversation::factory()->create([
        'subject' => null,
        'summary' => null,
        'last_message_preview' => null,
    ]);
    ConversationMessage::factory()->forConversation($quiet)->create([
        'sender_user_id' => null,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'a single needle-crowd mention from a quiet thread',
    ]);

    expect(($this->runList)(['search' => 'needle-crowd']))
        ->toEqualCanonicalizing([$spammy->id, $quiet->id]);
});

test('会话列表消息内容搜索要求中文关键词字符同时命中', function () {
    $previousDriver = config('scout.driver');
    $previousStorage = config('scout.tntsearch.storage');
    $previousFuzziness = config('scout.tntsearch.fuzziness');
    $previousBoolean = config('scout.tntsearch.searchBoolean');
    $scoutStorage = storage_path('framework/testing/scout-'.Str::random(8));
    File::ensureDirectoryExists($scoutStorage);

    config()->set('scout.driver', 'tntsearch');
    config()->set('scout.tntsearch.storage', $scoutStorage);
    config()->set('scout.tntsearch.fuzziness', false);
    config()->set('scout.tntsearch.searchBoolean', false);

    try {
        $matching = Conversation::factory()->create([
            'subject' => null,
            'summary' => null,
            'last_message_preview' => null,
        ]);
        ConversationMessage::factory()->forConversation($matching)->create([
            'sender_user_id' => null,
            'role' => MessageRole::Visitor,
            'kind' => MessageKind::Text,
            'content' => '订单退款流程已经提交',
        ]);

        $missingChar = Conversation::factory()->create([
            'subject' => null,
            'summary' => null,
            'last_message_preview' => null,
        ]);
        ConversationMessage::factory()->forConversation($missingChar)->create([
            'sender_user_id' => null,
            'role' => MessageRole::Visitor,
            'kind' => MessageKind::Text,
            'content' => '订单已经创建，正在等待仓库发货',
        ]);

        expect(($this->runList)(['search' => '订单退款']))->toEqual([$matching->id]);
    } finally {
        config()->set('scout.driver', $previousDriver);
        config()->set('scout.tntsearch.storage', $previousStorage);
        config()->set('scout.tntsearch.fuzziness', $previousFuzziness);
        config()->set('scout.tntsearch.searchBoolean', $previousBoolean);
        File::deleteDirectory($scoutStorage);
    }
});

test('会话列表消息内容搜索可匹配当前用户语言译文', function () {
    $this->user->update(['locale' => 'zh-CN']);
    $previousDriver = config('scout.driver');
    $previousStorage = config('scout.tntsearch.storage');
    $previousFuzziness = config('scout.tntsearch.fuzziness');
    $previousBoolean = config('scout.tntsearch.searchBoolean');
    $scoutStorage = storage_path('framework/testing/scout-'.Str::random(8));
    File::ensureDirectoryExists($scoutStorage);

    config()->set('scout.driver', 'tntsearch');
    config()->set('scout.tntsearch.storage', $scoutStorage);
    config()->set('scout.tntsearch.fuzziness', false);
    config()->set('scout.tntsearch.searchBoolean', false);

    try {
        $channel = Channel::factory()->create();
        $matching = Conversation::factory()->for($channel)->create([
            'subject' => null,
            'summary' => null,
            'last_message_preview' => null,
        ]);
        ConversationMessage::factory()->forConversation($matching)->visitorText()->create([
            'content' => 'I need a refund',
            'payload' => [
                'translations' => [
                    'zh-CN' => ['text' => '我需要退款'],
                ],
            ],
        ]);

        $otherLocaleConversation = Conversation::factory()->for($channel)->create([
            'subject' => null,
            'summary' => null,
            'last_message_preview' => null,
        ]);
        ConversationMessage::factory()->forConversation($otherLocaleConversation)->visitorText()->create([
            'content' => 'I need a return label',
            'payload' => [
                'translations' => [
                    'ja' => ['text' => '退款'],
                ],
            ],
        ]);

        expect(($this->runList)(['search' => '退款']))->toEqual([$matching->id]);
    } finally {
        config()->set('scout.driver', $previousDriver);
        config()->set('scout.tntsearch.storage', $previousStorage);
        config()->set('scout.tntsearch.fuzziness', $previousFuzziness);
        config()->set('scout.tntsearch.searchBoolean', $previousBoolean);
        File::deleteDirectory($scoutStorage);
    }
});
