<?php

use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
});

test('联系人时间线默认返回最新窗口并可以继续加载更早消息', function () {
    $contact = Contact::factory()->create([
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $baseTime = now()->subHour()->startOfSecond();
    $messages = collect(range(1, 5))->map(fn (int $index) => ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '第 '.$index.' 条消息',
        'sender_name' => '访客',
        'created_at' => $baseTime->copy()->addMinutes($index),
        'updated_at' => $baseTime->copy()->addMinutes($index),
    ]));

    expect(DB::table('conversation_timeline_entries')->where('entry_id', $messages[0]->id)->exists())->toBeTrue();

    $latest = $this->actingAs($this->user)
        ->getJson('/admin/inbox/contacts/'.$contact->id.'/timeline?per_page=3')
        ->assertOk()
        ->assertJsonPath('timeline.next_cursor', null)
        ->json('timeline');

    expect(collect($latest['entries'])->pluck('id')->all())->toBe([
        $messages[2]->id,
        $messages[3]->id,
        $messages[4]->id,
    ]);
    expect($latest['previous_cursor'])->not->toBeNull();

    $older = $this->actingAs($this->user)
        ->getJson('/admin/inbox/contacts/'.$contact->id.'/timeline?per_page=3&before='.urlencode($latest['previous_cursor']))
        ->assertOk()
        ->json('timeline');

    expect(collect($older['entries'])->pluck('id')->all())->toBe([
        $messages[0]->id,
        $messages[1]->id,
    ]);
    expect($older['previous_cursor'])->toBeNull();
    expect($older['next_cursor'])->not->toBeNull();
});

test('联系人时间线可以加载搜索消息所在的锚点窗口', function () {
    $contact = Contact::factory()->create([
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $baseTime = now()->subHour()->startOfSecond();
    $messages = collect(range(1, 6))->map(fn (int $index) => ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '锚点测试 '.$index,
        'sender_name' => '访客',
        'created_at' => $baseTime->copy()->addMinutes($index),
        'updated_at' => $baseTime->copy()->addMinutes($index),
    ]));

    $target = $messages[1];

    $timeline = $this->actingAs($this->user)
        ->getJson('/admin/inbox/contacts/'.$contact->id.'/timeline?per_page=3&anchor_type=message&anchor_id='.$target->id)
        ->assertOk()
        ->assertJsonPath('timeline.anchor_entry_id', $target->id)
        ->json('timeline');

    expect(collect($timeline['entries'])->pluck('id')->all())->toBe([
        $messages[0]->id,
        $messages[1]->id,
        $messages[2]->id,
    ]);
    expect($timeline['previous_cursor'])->toBeNull();
    expect($timeline['next_cursor'])->not->toBeNull();
});

test('联系人时间线按同一索引混合排序消息和事件', function () {
    $contact = Contact::factory()->create([
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $baseTime = now()->subHour()->startOfSecond();

    $firstMessage = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '事件前消息',
        'sender_name' => '访客',
        'created_at' => $baseTime->copy()->addMinute(),
        'updated_at' => $baseTime->copy()->addMinute(),
    ]);

    $event = ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::HandoffRequested,
        'payload' => ['reason' => 'user_requested'],
        'created_at' => $baseTime->copy()->addMinutes(2),
    ]);

    $secondMessage = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => '事件后消息',
        'sender_name' => '客服',
        'created_at' => $baseTime->copy()->addMinutes(3),
        'updated_at' => $baseTime->copy()->addMinutes(3),
    ]);

    $timeline = $this->actingAs($this->user)
        ->getJson('/admin/inbox/contacts/'.$contact->id.'/timeline?per_page=10')
        ->assertOk()
        ->json('timeline');

    expect(collect($timeline['entries'])->map(fn (array $entry) => [$entry['type'], $entry['id']])->all())->toBe([
        ['message', $firstMessage->id],
        ['event', $event->id],
        ['message', $secondMessage->id],
    ]);
});

test('联系人时间线分页只统计客服时间线展示的事件', function () {
    $contact = Contact::factory()->create([
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $baseTime = now()->subHour()->startOfSecond();

    $firstMessage = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '内部事件前消息',
        'sender_name' => '访客',
        'created_at' => $baseTime->copy()->addMinute(),
        'updated_at' => $baseTime->copy()->addMinute(),
    ]);

    ConversationEvent::factory()->forConversation($conversation)->create([
        'type' => ConversationEventType::ReceptionTurnStarted,
        'payload' => ['turn' => 1],
        'created_at' => $baseTime->copy()->addMinutes(2),
    ]);

    $secondMessage = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => '内部事件后消息',
        'sender_name' => '客服',
        'created_at' => $baseTime->copy()->addMinutes(3),
        'updated_at' => $baseTime->copy()->addMinutes(3),
    ]);

    $timeline = $this->actingAs($this->user)
        ->getJson('/admin/inbox/contacts/'.$contact->id.'/timeline?per_page=2')
        ->assertOk()
        ->json('timeline');

    expect(collect($timeline['entries'])->pluck('id')->all())->toBe([
        $firstMessage->id,
        $secondMessage->id,
    ]);
    expect($timeline['previous_cursor'])->toBeNull();
});
