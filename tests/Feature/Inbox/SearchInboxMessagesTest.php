<?php

use App\Actions\Inbox\SearchInboxMessagesAction;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
    $this->scoutStorage = storage_path('framework/testing/scout-'.Str::random(8));
    File::ensureDirectoryExists($this->scoutStorage);

    config()->set('scout.driver', 'tntsearch');
    config()->set('scout.tntsearch.storage', $this->scoutStorage);
    config()->set('scout.tntsearch.fuzziness', false);
    config()->set('scout.tntsearch.searchBoolean', false);
});

afterEach(function () {
    File::deleteDirectory($this->scoutStorage);
});

test('搜索联系人的聊天记录返回匹配消息', function () {
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => '张三',
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->create([
        'workspace_id' => $this->workspace->id,
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '我的订单发货了吗',
        'sender_name' => '张三',
    ]);

    ConversationMessage::factory()->create([
        'workspace_id' => $this->workspace->id,
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => '已经安排发货了',
        'sender_name' => '客服小王',
    ]);

    $results = SearchInboxMessagesAction::run($this->workspace, $this->user, $contact->id, '发货');

    expect($results)->toHaveCount(2);
    expect($results[0]->conversation_id)->toBe($conversation->id);
});

test('搜索不返回其他联系人的消息', function () {
    $contactA = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $contactB = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $conversationA = Conversation::factory()
        ->forContact($contactA)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $conversationB = Conversation::factory()
        ->forContact($contactB)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->create([
        'workspace_id' => $this->workspace->id,
        'conversation_id' => $conversationA->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '退款问题',
        'sender_name' => 'A',
    ]);

    ConversationMessage::factory()->create([
        'workspace_id' => $this->workspace->id,
        'conversation_id' => $conversationB->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '退款问题',
        'sender_name' => 'B',
    ]);

    $results = SearchInboxMessagesAction::run($this->workspace, $this->user, $contactA->id, '退款');

    expect($results)->toHaveCount(1);
    expect($results[0]->conversation_id)->toBe($conversationA->id);
});

test('中文消息搜索要求关键词字符同时命中', function () {
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'workspace_id' => $this->workspace->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '订单已经创建，正在等待仓库发货',
    ]);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'workspace_id' => $this->workspace->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '退款说明已经发送到邮箱',
    ]);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'workspace_id' => $this->workspace->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '订单退款流程已经提交',
    ]);

    ConversationMessage::factory()->forConversation($conversation)->create([
        'workspace_id' => $this->workspace->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '订单已完成，退款会在三个工作日内到账',
    ]);

    $results = SearchInboxMessagesAction::run($this->workspace, $this->user, $contact->id, '订单退款');

    expect(collect($results)->pluck('content')->all())->toEqualCanonicalizing([
        '订单退款流程已经提交',
        '订单已完成，退款会在三个工作日内到账',
    ]);
});

test('搜索联系人的聊天记录可匹配当前客服语言译文', function () {
    $this->user->update(['locale' => 'zh-CN']);
    $channel = Channel::factory()->for($this->workspace)->create();
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'I need a refund',
        'payload' => [
            'translations' => [
                'zh-CN' => ['text' => '我需要退款'],
            ],
        ],
    ]);

    $results = SearchInboxMessagesAction::run($this->workspace, $this->user, $contact->id, '退款');

    expect($results)->toHaveCount(1)
        ->and($results[0]->content)->toBe('I need a refund')
        ->and($results[0]->matched_content)->toBe('我需要退款');
});

test('搜索联系人的聊天记录按当前客服语言读取译文', function () {
    $this->user->update(['locale' => 'ja']);
    $channel = Channel::factory()->for($this->workspace)->create();
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'I need a refund',
        'payload' => [
            'translations' => [
                'zh-CN' => ['text' => '我需要退款'],
                'ja' => ['text' => '返金が必要です'],
            ],
        ],
    ]);

    $results = SearchInboxMessagesAction::run($this->workspace, $this->user, $contact->id, '返金');

    expect($results)->toHaveCount(1)
        ->and($results[0]->matched_content)->toBe('返金が必要です');
});

test('搜索联系人的聊天记录不会匹配其他客服语言译文', function () {
    $this->user->update(['locale' => 'en']);
    $channel = Channel::factory()->for($this->workspace)->create();
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'I need a refund',
        'payload' => [
            'translations' => [
                'zh-CN' => ['text' => '我需要退款'],
                'ja' => ['text' => '返金が必要です'],
            ],
        ],
    ]);

    $results = SearchInboxMessagesAction::run($this->workspace, $this->user, $contact->id, '退款');

    expect($results)->toHaveCount(0);
});

test('搜索接口需要认证', function () {
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->getJson('/w/'.$this->workspaceSlug().'/inbox/contacts/'.$contact->id.'/messages/search?search=test')
        ->assertUnauthorized();
});

test('搜索接口返回 JSON 结果', function () {
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => '李四',
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'workspace_id' => $this->workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->create([
        'workspace_id' => $this->workspace->id,
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '你好请问在吗',
        'sender_name' => '李四',
    ]);

    $this->actingAs($this->user)
        ->getJson('/w/'.$this->workspaceSlug().'/inbox/contacts/'.$contact->id.'/messages/search?search=你好')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.conversation_id', $conversation->id)
        ->assertJsonPath('results.0.content', '你好请问在吗')
        ->assertJsonPath('results.0.matched_content', '你好请问在吗')
        ->assertJsonPath('results.0.sender_name', '李四')
        ->assertJsonPath('results.0.role', 'visitor')
        ->assertJsonPath('results.0.role_label', '访客');
});

test('搜索关键词为空时返回验证错误', function () {
    $contact = Contact::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->getJson('/w/'.$this->workspaceSlug().'/inbox/contacts/'.$contact->id.'/messages/search?search=')
        ->assertUnprocessable();
});
