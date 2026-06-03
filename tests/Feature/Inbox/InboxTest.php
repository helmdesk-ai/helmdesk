<?php

use App\Actions\Inbox\ReplyInboxConversationAction;
use App\Actions\Reception\AppendTeammateMessageAction;
use App\Data\Conversation\ConversationSummaryData;
use App\Data\Inbox\FormReplyInboxConversationData;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Jobs\Inbox\TranslateInboxConversationMessageJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Attachment;
use App\Models\AttributeDefinition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactAttributeValue;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Models\Tag;
use App\Models\TagGroup;
use App\Models\TranslationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = $this->createUserWithSystem();

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
    ]);
});

function createInboxLlmModel(array $providerAttributes = [], array $modelAttributes = []): AiModel
{
    $provider = AiProvider::query()->create(array_merge([
        'brand' => 'custom-openai',
        'slug' => 'inbox-provider-'.Str::lower(Str::random(6)),
        'name' => 'Inbox Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ], $providerAttributes));

    return AiModel::query()->create(array_merge([
        'ai_provider_id' => $provider->id,
        'name' => 'Inbox Model',
        'model_id' => 'gpt-inbox',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ], $modelAttributes));
}

/**
 * 构造一个绑定指定（或自动生成）AI 模型的接待方案版本，供需要 AI 可用性的会话用例使用。
 */
function createInboxReceptionPlanVersion(?AiModel $model = null, ?TranslationProvider $translationProvider = null): ReceptionPlanVersion
{
    $model ??= createInboxLlmModel();
    $plan = ReceptionPlan::factory()->create([
        'name' => '收件箱测试方案-'.Str::lower(Str::random(6)),
    ]);

    $factory = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel($model->id);

    if ($translationProvider !== null) {
        $factory = $factory->state(function (array $attributes) use ($translationProvider): array {
            $snapshot = $attributes['snapshot_config'] ?? [];
            $snapshot['translation_config'] = [
                'enabled' => true,
                'failure_mode' => 'skip',
                'provider_id' => $translationProvider->id,
            ];

            return ['snapshot_config' => $snapshot];
        });
    }

    return $factory->create();
}

/**
 * 构造一个接待方案版本，并在快照里写入可用翻译供应商。
 */
function createInboxTranslationPlanVersion(?AiModel $model = null): ReceptionPlanVersion
{
    $translationProvider = TranslationProvider::factory()->create();

    return createInboxReceptionPlanVersion($model, $translationProvider);
}

test('收件箱默认进入待处理视图，让同事进入需要处理的队列', function () {
    $contact = Contact::factory()->create([
        'name' => 'Mia',
    ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 2,
        ]);

    $needsHuman = Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'assigned_user_id' => null,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->where('current_view', 'pending')
            ->where('current_channel_id', null)
            ->where('current_assignee', null)
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $needsHuman->id)
            ->has('enabled_web_channels')
            ->has('teammates')
        );
});

test('收件箱支持重点客户筛选并在开放视图优先显示重点客户', function () {
    $importantContact = Contact::factory()->create([
        'name' => 'Important Mia',
        'is_important' => true,
        'important_at' => now()->subDay(),
        'important_source' => 'manual',
    ]);
    $normalContact = Contact::factory()->create([
        'name' => 'Normal Nora',
    ]);

    $importantConversation = Conversation::factory()
        ->forContact($importantContact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'last_message_at' => now()->subDays(2),
        ]);
    $normalConversation = Conversation::factory()
        ->forContact($normalContact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'last_message_at' => now(),
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=pending')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_important_only', false)
            ->where('conversation_list.0.id', $importantConversation->id)
            ->where('conversation_list.0.contact_is_important', true)
            ->where('conversation_list.1.id', $normalConversation->id)
        );

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=pending&important=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_important_only', true)
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $importantConversation->id)
            ->where('conversation_list.0.contact_is_important', true)
        );
});

test('收件箱拒绝无效视图查询', function () {
    $this->actingAs($this->user)
        ->from('/admin/inbox')
        ->get('/admin/inbox?view=unknown')
        ->assertRedirect('/admin/inbox')
        ->assertSessionHasErrors('view');
});

test('单租户收件箱允许筛选任意后台用户和渠道', function () {
    $otherUser = User::factory()->create();
    $otherChannel = Channel::factory()->create();

    $this->actingAs($this->user)
        ->get('/admin/inbox?channel='.$otherChannel->id)
        ->assertOk();

    $this->actingAs($this->user)
        ->get('/admin/inbox?assignee='.$otherUser->id)
        ->assertOk();
});

test('收件箱选中项会把同一联系人的所有会话合并为单一时间线', function () {
    $this->user->forceFill([
        'avatar' => 'https://example.com/operator.png',
    ])->save();

    $contact = Contact::factory()->create([
        'name' => 'Nova',
    ]);

    $oldClosed = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([]);

    ConversationMessage::query()->create([
        'conversation_id' => $oldClosed->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'Hello from old conversation',
    ]);

    $openNow = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $openNow->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'Hi again, new question',
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $openNow->id,
        'sender_user_id' => $this->user->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'I can help from Nova Support',
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&conversation_id='.$openNow->id)
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($contact, $openNow) {
            $entries = collect($page->toArray()['props']['selection']['stitched_timeline']['entries']);
            $teammateEntry = $entries->firstWhere('content', 'I can help from Nova Support');

            expect($teammateEntry)->not->toBeNull()
                ->and($teammateEntry['sender_name'])->toBe($this->user->name)
                ->and($teammateEntry['sender_avatar_url'])->toBe('https://example.com/operator.png');

            $page
                ->where('selection.conversation.id', $openNow->id)
                ->where('selection.conversation.assigned_user_id', $this->user->id)
                ->where('selection.conversation.assigned_user_name', $this->user->name)
                ->where('selection.contact.id', $contact->id)
                ->has('selection.stitched_timeline.conversations', 2)
                ->has('selection.stitched_timeline.entries')
                ->where('selection.can_reply', true);
        });
});

test('会话摘要数据带出渠道身份用于多渠道上下文头', function () {
    $contact = Contact::factory()->create([
        'name' => 'Mia',
    ]);

    $channel = Channel::factory()->telegram()->create([
        'name' => 'Nova Support Bot',
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'channel_id' => $channel->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $conversation->load('channel');
    $data = ConversationSummaryData::fromModel($conversation);

    expect($data->channel_type)->toBe('telegram')
        ->and($data->channel_type_label)->toBe('Telegram')
        ->and($data->channel_name)->toBe('Nova Support Bot');
});

test('会话摘要数据在未加载渠道关系时不暴露渠道身份', function () {
    $contact = Contact::factory()->create([
    ]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([]);

    $data = ConversationSummaryData::fromModel($conversation);

    expect($data->channel_type)->toBeNull()
        ->and($data->channel_type_label)->toBeNull()
        ->and($data->channel_name)->toBeNull();
});

test('收件箱选择暴露联系人标签和自定义属性用于配置档面板', function () {
    $contact = Contact::factory()->create([
        'name' => 'Profile Contact',
        'note' => 'VIP customer',
        'is_important' => true,
        'important_at' => now()->subDay(),
        'important_source' => 'manual',
    ]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $contactGroup = TagGroup::factory()->contact()->create([]);
    $conversationGroup = TagGroup::factory()->conversation()->create([]);
    $contactTag = Tag::factory()->forGroup($contactGroup)->create(['name' => 'Important']);
    $conversationTag = Tag::factory()->forGroup($conversationGroup)->create(['name' => 'Conversation Only']);
    $emailIdentity = ContactIdentity::factory()->email('profile@example.com')->create([
        'contact_id' => $contact->id,
    ]);
    $phoneIdentity = ContactIdentity::factory()->phone('+8613800000000')->create([
        'contact_id' => $contact->id,
    ]);
    $contact->syncPrimaryFields();

    DB::table('contact_tag_assignments')->insert([
        'tag_id' => $contactTag->id,
        'contact_id' => $contact->id,
        'assigned_by_user_id' => $this->user->id,
        'source' => 'manual',
        'created_at' => now(),
    ]);

    $definition = AttributeDefinition::factory()->text()->create([
        'key' => 'plan',
        'name' => 'Plan',
    ]);
    ContactAttributeValue::factory()->forText('Enterprise')->create([
        'contact_id' => $contact->id,
        'definition_id' => $definition->id,
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($contactTag, $conversationTag, $emailIdentity, $phoneIdentity) {
            $props = $page->toArray()['props'];
            $availableContactTagIds = collect($props['available_contact_tags'])->pluck('id')->all();
            $availableConversationTagIds = collect($props['available_conversation_tags'])->pluck('id')->all();

            // 标签选项按维度分流：联系人选择器只出联系人标签，会话选择器只出会话标签。
            expect($availableContactTagIds)
                ->toContain($contactTag->id)
                ->not->toContain($conversationTag->id);
            expect($availableConversationTagIds)
                ->toContain($conversationTag->id)
                ->not->toContain($contactTag->id);

            $page
                ->where('selection.contact_profile.note', 'VIP customer')
                ->where('selection.contact.is_important', true)
                ->where('selection.contact_profile.is_important', true)
                ->where('selection.contact_profile.primary_email', 'profile@example.com')
                ->where('selection.contact_profile.primary_email_identity_id', $emailIdentity->id)
                ->where('selection.contact_profile.primary_phone', '+8613800000000')
                ->where('selection.contact_profile.primary_phone_identity_id', $phoneIdentity->id)
                ->where('selection.contact_profile.tags.0.id', $contactTag->id)
                ->where('selection.contact_profile.custom_attributes.0.key', 'plan')
                ->where('selection.contact_profile.custom_attributes.0.value', 'Enterprise');
        });
});

test('收件箱选中会话下发会话标签，且联系人资料带咨询概况聚合', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $group = TagGroup::factory()->conversation()->create([]);
    $refund = Tag::factory()->forGroup($group)->create(['name' => '退款']);

    DB::table('conversation_tag_assignments')->insert([
        'conversation_id' => $conversation->id,
        'tag_id' => $refund->id,
        'source' => 'ai',
        'confidence' => 0.91,
        'reason' => '客户要求退款',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($refund) {
            // 当前会话块走 selection.conversation（不是时间线），其 tags 同样要带上。
            $currentTags = collect($page->toArray()['props']['selection']['conversation']['tags']);
            expect($currentTags->pluck('id')->all())->toContain($refund->id);
            expect($currentTags->firstWhere('id', $refund->id)['source'])->toBe('ai');

            $conversations = collect(
                $page->toArray()['props']['selection']['stitched_timeline']['conversations'],
            );
            $current = $conversations->firstWhere('id', null) ?? $conversations->first();

            expect(collect($current['tags'])->pluck('id')->all())->toContain($refund->id);
            expect(collect($current['tags'])->firstWhere('id', $refund->id)['source'])->toBe('ai');

            $aggregates = collect(
                $page->toArray()['props']['selection']['contact_profile']['conversation_tag_aggregates'],
            );
            expect($aggregates->firstWhere('tag_id', $refund->id)['count'])->toBe(1);
        });
});

test('同事可以回复收件箱会话并连接到AppendTeammateMessageAction', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/reply', [
            'content' => 'Got it, looking into this now.',
            'kind' => 'text',
        ])
        ->assertRedirect();

    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Teammate)
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('Got it, looking into this now.');

    $conversation->refresh();
    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and($conversation->waiting_for_visitor_reply)->toBeTrue()
        ->and($conversation->unread_visitor_message_count)->toBe(0);
});

test('同事回复可保存发送前确认的访客可见内容', function () {
    Bus::fake();

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['locale' => 'en']);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'visitor_locale' => 'en',
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/reply', [
            'content' => '我马上帮您查一下。',
            'visitor_content' => 'I will check that for you right away.',
            'visitor_locale' => 'en',
            'source_locale' => 'zh-CN',
        ])
        ->assertRedirect();

    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Teammate)
        ->firstOrFail();

    expect($message->content)->toBe('I will check that for you right away.')
        ->and($message->content_locale)->toBe('en')
        ->and($message->payload['translations']['zh-CN']['text'])->toBe('我马上帮您查一下。')
        ->and($message->payload['translations']['zh-CN']['provider_slug'])->toBe('author');
});

test('同事和访客语言一致时回复不需要翻译确认内容', function () {
    $this->user->update(['locale' => 'en']);

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['locale' => 'en']);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'visitor_locale' => 'en',
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/reply', [
            'content' => 'Hello team',
        ])
        ->assertRedirect();

    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Teammate)
        ->firstOrFail();

    expect($message->content)->toBe('Hello team')
        ->and($message->content_locale)->toBeNull()
        ->and($message->payload)->toBeNull();
});

test('同事可为当前可见消息排队补翻到自己的语言', function () {
    Bus::fake();
    $this->user->update(['locale' => 'ja']);
    $planVersion = createInboxTranslationPlanVersion();

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'reception_plan_version_id' => $planVersion->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);
    $aiMessage = ConversationMessage::factory()->forConversation($conversation)->aiText()->create(['content' => 'AI answer']);
    $otherTeammate = User::factory()->create();
    $this->attachSystem($otherTeammate, $this->systemContext);
    $teammateMessage = ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'Agent answer',
        'sender_user_id' => $otherTeammate->id,
    ]);
    $ownMessage = ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'Own answer',
        'sender_user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->postJson('/admin/inbox/'.$conversation->id.'/messages/queue-translations', [
            'message_ids' => [
                (string) $message->id,
                (string) $aiMessage->id,
                (string) $teammateMessage->id,
                (string) $ownMessage->id,
            ],
        ])
        ->assertOk()
        ->assertJson(['queued_count' => 3]);

    Bus::assertDispatched(
        TranslateInboxConversationMessageJob::class,
        fn (TranslateInboxConversationMessageJob $job): bool => $job->messageId === (string) $message->id
            && $job->targetLocale === 'ja',
    );
    Bus::assertDispatched(
        TranslateInboxConversationMessageJob::class,
        fn (TranslateInboxConversationMessageJob $job): bool => $job->messageId === (string) $aiMessage->id
            && $job->targetLocale === 'ja',
    );
    Bus::assertDispatched(
        TranslateInboxConversationMessageJob::class,
        fn (TranslateInboxConversationMessageJob $job): bool => $job->messageId === (string) $teammateMessage->id
            && $job->targetLocale === 'ja',
    );
    Bus::assertNotDispatched(
        TranslateInboxConversationMessageJob::class,
        fn (TranslateInboxConversationMessageJob $job): bool => $job->messageId === (string) $ownMessage->id,
    );
});

test('收件箱打开会话保留可见消息翻译能力', function () {
    $planVersion = createInboxTranslationPlanVersion();

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'reception_plan_version_id' => $planVersion->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'Hello',
        'payload' => [
            'translations' => [
                'zh-CN' => ['text' => '你好'],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?conversation_id='.$conversation->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.can_translate_messages', true));
});

test('打开会话时保留访客消息补翻能力', function () {
    $planVersion = createInboxTranslationPlanVersion();

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'reception_plan_version_id' => $planVersion->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    $this->actingAs($this->user)
        ->get('/admin/inbox?conversation_id='.$conversation->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.can_translate_messages', true)
            ->where('selection.reply_visitor_locale', 'zh-CN')
        );
});

test('未配置默认翻译供应商时收件箱不提供消息补翻能力', function () {
    Bus::fake();

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'Hello',
        'content_locale' => 'en',
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?conversation_id='.$conversation->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.can_translate_messages', false)
        );

    $this->actingAs($this->user)
        ->postJson('/admin/inbox/'.$conversation->id.'/messages/queue-translations', [
            'message_ids' => [(string) $message->id],
        ])
        ->assertOk()
        ->assertJson(['queued_count' => 0]);

    Bus::assertNotDispatched(TranslateInboxConversationMessageJob::class);
});

test('关闭会话保留可见消息补翻能力', function () {
    Bus::fake();
    $planVersion = createInboxTranslationPlanVersion();

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'reception_plan_version_id' => $planVersion->id,
            'visitor_locale' => 'en',
        ]);

    $visitorMessage = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'Hello',
        'content_locale' => 'en',
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=closed&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_reply', false)
            ->where('selection.can_translate_messages', true)
        );

    $this->actingAs($this->user)
        ->postJson('/admin/inbox/'.$conversation->id.'/messages/queue-translations', [
            'message_ids' => [(string) $visitorMessage->id],
        ])
        ->assertOk()
        ->assertJson(['queued_count' => 1]);

    Bus::assertDispatched(
        TranslateInboxConversationMessageJob::class,
        fn (TranslateInboxConversationMessageJob $job): bool => $job->messageId === (string) $visitorMessage->id
            && $job->targetLocale === 'zh-CN',
    );
});

test('AI接待中同事可以补翻消息到自己的语言', function () {
    Bus::fake();
    $planVersion = createInboxTranslationPlanVersion();
    $channel = Channel::factory()->create([
        'reception_plan_id' => $planVersion->reception_plan_id,
        'reception_plan_version_id' => $planVersion->id,
    ]);
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->create([
            'assigned_user_id' => null,
            'reception_plan_version_id' => $planVersion->id,
            'visitor_locale' => 'en',
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);
    $aiMessage = ConversationMessage::factory()->forConversation($conversation)->aiText()->create([
        'content' => 'Hello, how can I help you?',
        'content_locale' => 'en',
    ]);
    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'I need help',
        'content_locale' => 'en',
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=ai&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.can_reply', false)
            ->where('selection.can_translate_messages', true));

    $this->actingAs($this->user)
        ->postJson('/admin/inbox/'.$conversation->id.'/messages/queue-translations', [
            'message_ids' => [(string) $aiMessage->id],
        ])
        ->assertOk()
        ->assertJson(['queued_count' => 1]);

    Bus::assertDispatched(
        TranslateInboxConversationMessageJob::class,
        fn (TranslateInboxConversationMessageJob $job): bool => $job->messageId === (string) $aiMessage->id
            && $job->targetLocale === 'zh-CN',
    );
});

test('同事回复预览返回访客可见内容', function () {
    $planVersion = createInboxTranslationPlanVersion();
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => 'I will check that for you right away.', 'detectedSourceLanguage' => 'zh-CN'],
                ],
            ],
        ]),
    ]);

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['locale' => 'en']);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'reception_plan_version_id' => $planVersion->id,
            'visitor_locale' => 'en',
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->postJson('/admin/inbox/'.$conversation->id.'/reply/translation-preview', [
            'content' => '我马上帮您查一下。',
        ])
        ->assertOk()
        ->assertJson([
            'visitor_content' => 'I will check that for you right away.',
            'visitor_locale' => 'en',
            'source_locale' => 'zh-CN',
        ]);
});

test('同事回复预览同语言时不请求翻译供应商', function () {
    $this->user->update(['locale' => 'en']);

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['locale' => 'en']);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'visitor_locale' => 'en',
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->postJson('/admin/inbox/'.$conversation->id.'/reply/translation-preview', [
            'content' => 'Hello team',
        ])
        ->assertOk()
        ->assertJson([
            'visitor_content' => 'Hello team',
            'visitor_locale' => 'en',
            'source_locale' => 'en',
        ]);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'translation.googleapis.com'));
});

test('同事回复预览不可回复时返回空预览', function () {
    $teammate = User::factory()->create();
    $this->attachSystem($teammate, $this->systemContext);

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['locale' => 'en']);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($teammate)
        ->create([
            'visitor_locale' => 'en',
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->postJson('/admin/inbox/'.$conversation->id.'/reply/translation-preview', [
            'content' => '我马上帮您查一下。',
        ])
        ->assertOk()
        ->assertJson([
            'visitor_content' => null,
            'visitor_locale' => null,
            'source_locale' => null,
        ]);
});

test('同事回复提交时访客语言变化会拒绝过期翻译', function () {
    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['locale' => 'en']);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->assignedTo($this->user)
        ->create([
            'visitor_locale' => 'ja',
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    expect(fn () => app(ReplyInboxConversationAction::class)->handle(
        user: $this->user,
        conversationId: (string) $conversation->id,
        data: new FormReplyInboxConversationData(
            content: '我马上帮您查一下。',
            visitor_content: 'I will check that for you right away.',
            visitor_locale: 'en',
            source_locale: 'zh-CN',
        ),
    ))->toThrow(BusinessException::class, __('conversation.errors.reply_translation_stale'));
});

test('同事可以回复并发送仅附件文件消息', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);
    $attachment = Attachment::factory()->create([
        'uploaded_by_user_id' => $this->user->id,
        'purpose' => 'conversation_file',
        'original_name' => 'manual.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'byte_size' => 2048,
        'status' => 'uploaded',
    ]);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/reply', [
            'content' => '',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertRedirect();

    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Teammate)
        ->firstOrFail();

    expect($message->kind)->toBe(MessageKind::File)
        ->and($message->content)->toBeNull()
        ->and($message->payload['attachments'][0]['id'])->toBe((string) $attachment->id)
        ->and($attachment->fresh()->attachable_id)->toBe($message->id);
});

test('回复需要人工处理的会话会将操作员移到我的视图并选中该会话', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
        ]);

    $redirectTo = route('admin.inbox.show', ['view' => 'mine',
        'conversation_id' => $conversation->id,
    ], false);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/reply', [
            'content' => '我来处理，稍等一下。',
        ])
        ->assertRedirect($redirectTo);

    $conversation->refresh();
    expect((string) $conversation->assigned_user_id)->toBe((string) $this->user->id)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and($conversation->waiting_for_visitor_reply)->toBeTrue();

    $this->actingAs($this->user)
        ->get($redirectTo)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'mine')
            ->where('current_conversation_id', $conversation->id)
            ->where('conversation_list.0.id', $conversation->id)
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.conversation.assigned_user_id', $this->user->id)
            ->has('selection.stitched_timeline.entries')
        );
});

test('回复来自收件箱刷新同事最后活跃时间戳', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
        ]);

    $previousLastActiveAt = now()->subDay();
    $this->user->forceFill(['last_active_at' => $previousLastActiveAt])->save();

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/reply', [
            'content' => '马上帮你处理。',
        ])
        ->assertRedirect();

    $updatedLastActiveAt = $this->user->fresh()->last_active_at;

    expect($updatedLastActiveAt)->not->toBeNull()
        ->and($updatedLastActiveAt->isAfter($previousLastActiveAt))->toBeTrue();
});

test('同事可以认领teammate_pending会话来自收件箱', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
        ]);

    $redirectTo = route('admin.inbox.show', ['view' => 'mine',
        'conversation_id' => $conversation->id,
    ], false);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/claim')
        ->assertRedirect($redirectTo);

    $conversation->refresh();
    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and((string) $conversation->assigned_user_id)->toBe((string) $this->user->id);
});

test('同事可以转移AI会话到人工来自收件箱', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    $redirectTo = route('admin.inbox.show', ['view' => 'mine',
        'conversation_id' => $conversation->id,
    ], false);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/claim')
        ->assertRedirect($redirectTo);

    $conversation->refresh();
    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and((string) $conversation->assigned_user_id)->toBe((string) $this->user->id);
});

test('AI会话需要先转人工后同事才能回复', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=ai&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_claim', true)
            ->where('selection.can_reply', false)
        );

    expect(fn () => app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation,
        actor: $this->user,
        content: 'I will take this one.',
    ))->toThrow(BusinessException::class);

    $conversation->refresh();
    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling)
        ->and($conversation->assigned_user_id)->toBeNull();
});

test('AI视图包含等待访客的未分配会话', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
            'waiting_for_visitor_reply' => true,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=ai&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'ai')
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $conversation->id)
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_claim', true)
            ->where('selection.can_reply', false)
        );

    expect(fn () => app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation,
        actor: $this->user,
        content: 'I will take this one.',
    ))->toThrow(BusinessException::class);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/claim')
        ->assertRedirect(route('admin.inbox.show', ['view' => 'mine',
            'conversation_id' => $conversation->id,
        ], false));

    $conversation->refresh();
    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and((string) $conversation->assigned_user_id)->toBe((string) $this->user->id)
        ->and($conversation->waiting_for_visitor_reply)->toBeTrue();
});

test('同事视图会将同事处理中的会话显示为可接管候选项', function () {
    $contact = Contact::factory()->create([]);
    $teammate = User::factory()->create();
    $this->attachSystem($teammate, $this->systemContext);

    $colleagueConversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($teammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->closed()
        ->create([]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=teammates&conversation_id='.$colleagueConversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'teammates')
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $colleagueConversation->id)
            ->where('selection.conversation.id', $colleagueConversation->id)
            ->where('selection.can_claim', true)
            ->where('selection.can_reply', false)
            ->where('selection.can_release_to_ai', false)
            ->where('selection.can_close', false)
        );
});

test('同事可以接管同事处理中会话来自收件箱', function () {
    $contact = Contact::factory()->create([]);
    $teammate = User::factory()->create();
    $this->attachSystem($teammate, $this->systemContext);

    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->assignedTo($teammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $redirectTo = route('admin.inbox.show', ['view' => 'mine',
        'conversation_id' => $conversation->id,
    ], false);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/claim')
        ->assertRedirect($redirectTo);

    $conversation->refresh();
    $event = ConversationEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('type', ConversationEventType::AssignmentChanged)
        ->latest('created_at')
        ->firstOrFail();

    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and((string) $conversation->assigned_user_id)->toBe((string) $this->user->id)
        ->and($event->payload['source'])->toBe('takeover')
        ->and($event->payload['previous_user_id'])->toBe((string) $teammate->id)
        ->and($event->payload['user_id'])->toBe((string) $this->user->id);
});

test('同事可以转移其已分配会话到已选择同事', function () {
    $contact = Contact::factory()->create([]);
    $targetTeammate = User::factory()->create();
    $this->attachSystem($targetTeammate, $this->systemContext);

    $conversation = Conversation::factory()
        ->withReceptionPlanVersion($this->systemContext)
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_transfer_to_teammate', true)
        );

    $redirectTo = route('admin.inbox.show', ['view' => 'teammates',
        'conversation_id' => $conversation->id,
    ], false);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/transfer', [
            'target_user_id' => $targetTeammate->id,
        ])
        ->assertRedirect($redirectTo);

    $conversation->refresh();
    $event = ConversationEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('type', ConversationEventType::AssignmentChanged)
        ->latest('created_at')
        ->firstOrFail();

    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and((string) $conversation->assigned_user_id)->toBe((string) $targetTeammate->id)
        ->and($event->payload['source'])->toBe('transfer_to_teammate')
        ->and($event->payload['previous_user_id'])->toBe((string) $this->user->id)
        ->and($event->payload['user_id'])->toBe((string) $targetTeammate->id);

    $this->actingAs($this->user)
        ->get($redirectTo)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'teammates')
            ->where('current_assignee', null)
            ->where('selection.conversation.assigned_user_id', $targetTeammate->id)
            ->where('selection.can_claim', true)
            ->where('selection.can_reply', false)
        );
});

test('同事不能转移会话已分配到同事', function () {
    $contact = Contact::factory()->create([]);
    $ownerTeammate = User::factory()->create();
    $targetTeammate = User::factory()->create();
    $this->attachSystem($ownerTeammate, $this->systemContext);
    $this->attachSystem($targetTeammate, $this->systemContext);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($ownerTeammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/transfer', [
            'target_user_id' => $targetTeammate->id,
        ])
        ->assertStatus(422)
        ->assertJson([
            'message' => '只能转接自己正在接待的会话。',
        ]);

    $conversation->refresh();
    expect((string) $conversation->assigned_user_id)->toBe((string) $ownerTeammate->id);
});

test('同事视图可以被缩小到指定同事', function () {
    $contact = Contact::factory()->create([]);
    $targetTeammate = User::factory()->create();
    $otherTeammate = User::factory()->create();
    $this->attachSystem($targetTeammate, $this->systemContext);
    $this->attachSystem($otherTeammate, $this->systemContext);

    $matchingConversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($targetTeammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($otherTeammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=teammates&assignee='.$targetTeammate->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'teammates')
            ->where('current_assignee', $targetTeammate->id)
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $matchingConversation->id)
        );
});

test('同事可以释放其已分配会话回到AI在回复后', function () {
    $version = createInboxReceptionPlanVersion();
    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $version->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'waiting_for_visitor_reply' => true,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $this->user->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'Please check and reply later.',
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_release_to_ai', true)
            ->where('selection.release_to_ai_will_use_ai', true)
        );

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/release-to-ai')
        ->assertRedirect(route('admin.inbox.show', ['view' => 'ai',
            'conversation_id' => $conversation->id,
        ], false));

    $conversation->refresh();
    expect($conversation->assigned_user_id)->toBeNull()
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling)
        ->and($conversation->waiting_for_visitor_reply)->toBeTrue();
});

test('访客消息后释放给AI会让AI准备回答', function () {
    $version = createInboxReceptionPlanVersion();
    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $version->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'Can AI continue from here?',
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_release_to_ai', true)
            ->where('selection.release_to_ai_will_use_ai', true)
        );

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/release-to-ai')
        ->assertRedirect(route('admin.inbox.show', ['view' => 'ai',
            'conversation_id' => $conversation->id,
        ], false));

    $conversation->refresh();
    expect($conversation->assigned_user_id)->toBeNull()
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling)
        ->and($conversation->waiting_for_visitor_reply)->toBeFalse();
});

test('频道模型不可用时释放给AI会回退到待处理队列', function () {
    $model = createInboxLlmModel([], ['is_active' => false]);
    $version = createInboxReceptionPlanVersion($model);
    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $version->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'Can AI continue from here?',
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&conversation_id='.$conversation->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_release_to_ai', true)
            ->where('selection.release_to_ai_will_use_ai', false)
        );

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/release-to-ai')
        ->assertRedirect(route('admin.inbox.show', ['view' => 'pending',
            'conversation_id' => $conversation->id,
        ], false));

    $conversation->refresh();
    expect($conversation->assigned_user_id)->toBeNull()
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending)
        ->and($conversation->waiting_for_visitor_reply)->toBeFalse();
});

test('同事可以直接关闭会话', function () {
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
        ]);

    $redirectTo = route('admin.inbox.show', ['view' => 'closed',
        'conversation_id' => $conversation->id,
    ], false);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/close')
        ->assertRedirect($redirectTo);

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Closed)
        ->and($conversation->closed_at)->not->toBeNull();

    $this->actingAs($this->user)
        ->get($redirectTo)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'closed')
            ->where('current_conversation_id', $conversation->id)
            ->where('conversation_list.0.id', $conversation->id)
            ->where('selection.conversation.id', $conversation->id)
            ->where('selection.can_reply', false)
            ->where('selection.can_reopen', true)
        );
});

test('同事可以重新打开已关闭会话来自收件箱', function () {
    $contact = Contact::factory()->create([]);
    $channel = Channel::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'channel_id' => $channel->id,
        ]);

    $redirectTo = route('admin.inbox.show', ['view' => 'mine',
        'conversation_id' => $conversation->id,
    ], false);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/reopen')
        ->assertRedirect($redirectTo);

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling)
        ->and($conversation->assigned_user_id)->toBe($this->user->id)
        ->and($conversation->closed_at)->toBeNull();
});

test('同一联系人频道已有打开会话时已关闭收件箱不允许重新打开', function () {
    $contact = Contact::factory()->create([]);
    $channel = Channel::factory()->create([]);
    $closedConversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'channel_id' => $channel->id,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->create([
            'channel_id' => $channel->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$closedConversation->id.'/reopen')
        ->assertStatus(422)
        ->assertJson([
            'message' => '该客户在当前渠道已有进行中会话，不能恢复这条已关闭会话。',
        ]);

    $closedConversation->refresh();
    expect($closedConversation->status)->toBe(ConversationStatus::Closed);
});

test('同一联系人出现新的打开会话后已关闭收件箱会隐藏旧的已关闭会话', function () {
    $contact = Contact::factory()->create([]);
    $channel = Channel::factory()->create([]);

    $closedConversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'channel_id' => $channel->id,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->create([
            'channel_id' => $channel->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    $this->actingAs($this->user)
        ->get(route('admin.inbox.show', ['view' => 'closed',
            'conversation_id' => $closedConversation->id,
        ], false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'closed')
            ->has('conversation_list', 0)
            ->where('current_conversation_id', null)
            ->where('selection', null)
        );
});

test('已关闭收件箱仅保留同一联系人和频道的最新已关闭会话', function () {
    $contact = Contact::factory()->create([]);
    $channelA = Channel::factory()->create(['name' => 'Channel A']);
    $channelB = Channel::factory()->create(['name' => 'Channel B']);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'channel_id' => $channelA->id,
            'closed_at' => now()->subDays(3),
            'last_message_at' => now()->subDays(3),
            'created_at' => now()->subDays(4),
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'channel_id' => $channelA->id,
            'closed_at' => now()->subDays(2),
            'last_message_at' => now()->subDays(2),
            'created_at' => now()->subDays(3),
        ]);

    $latestOnChannelA = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'channel_id' => $channelA->id,
            'closed_at' => now()->subDay(),
            'last_message_at' => now()->subDay(),
            'created_at' => now()->subDays(2),
        ]);

    $closedOnChannelB = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'channel_id' => $channelB->id,
            'closed_at' => now()->subDays(4),
            'last_message_at' => now()->subDays(4),
            'created_at' => now()->subDays(5),
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=closed')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'closed')
            ->has('conversation_list', 2)
            ->where('conversation_list.0.id', $latestOnChannelA->id)
            ->where('conversation_list.1.id', $closedOnChannelB->id)
        );
});

test('我的视图结合并带频道筛选缩小已分配列表到频道只', function () {
    $contact = Contact::factory()->create([]);
    $channelA = Channel::factory()->create(['name' => 'Channel A']);
    $channelB = Channel::factory()->create(['name' => 'Channel B']);

    $assignedOnA = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'channel_id' => $channelA->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'channel_id' => $channelB->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&channel='.$channelA->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'mine')
            ->where('current_channel_id', $channelA->id)
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $assignedOnA->id)
        );
});

test('我的视图搜索缩小已分配列表按联系人和会话摘要', function () {
    $matchingContact = Contact::factory()->create([
        'name' => 'Avery Billing',
    ]);
    $otherContact = Contact::factory()->create([
        'name' => 'Nova Support',
    ]);

    $matchingConversation = Conversation::factory()
        ->forContact($matchingContact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'last_message_preview' => 'I need a refund for this order',
        ]);

    Conversation::factory()
        ->forContact($otherContact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'last_message_preview' => 'Question about setup',
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine&search=refund')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'mine')
            ->where('current_search', 'refund')
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $matchingConversation->id)
        );
});

test('AI视图结合并带负责人未分配缩小AI会话到未分配只', function () {
    $contactA = Contact::factory()->create([]);
    $contactB = Contact::factory()->create([]);

    $unassignedAi = Conversation::factory()
        ->forContact($contactA)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    Conversation::factory()
        ->forContact($contactB)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=ai&assignee=unassigned')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'ai')
            ->where('current_assignee', 'unassigned')
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $unassignedAi->id)
        );
});

test('已关闭视图并带显式负责人筛选严格按assigned_user_id和忽略谁已关闭它', function () {
    $contact = Contact::factory()->create([]);
    $teammate = User::factory()->create();
    $this->attachSystem($teammate, $this->systemContext);

    $closedAssignedToTeammate = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($teammate)
        ->closed()
        ->create([]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=closed&assignee='.$teammate->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'closed')
            ->where('current_assignee', $teammate->id)
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $closedAssignedToTeammate->id)
        );
});

test('会话列表将本人会话的冗余未读访客消息数报告为unread_count', function () {
    $contact = Contact::factory()->create([]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 2,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'first visitor message',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'teammate replied',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'follow up question',
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinutes(2),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'and another one',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_view', 'mine')
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $conversation->id)
            ->where('conversation_list.0.unread_count', 2)
        );
});

test('操作员最后回复后会话列表unread_count为零', function () {
    $contact = Contact::factory()->create([]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'waiting_for_visitor_reply' => true,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor asked',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'teammate replied last',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $conversation->id)
            ->where('conversation_list.0.unread_count', 0)
        );
});

test('非本人待接待会话不显示unread_count', function () {
    $contact = Contact::factory()->create([]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'assigned_user_id' => null,
            'unread_visitor_message_count' => 3,
        ]);

    foreach (range(1, 3) as $i) {
        ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Visitor,
            'kind' => MessageKind::Text,
            'content' => 'visitor message #'.$i,
            'created_at' => now()->subMinutes(10 - $i),
            'updated_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=pending')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversation_list', 1)
            ->where('conversation_list.0.id', $conversation->id)
            ->where('conversation_list.0.unread_count', 0)
        );
});

test('打开会话会按当前客服清空unread_count且新访客消息会重新计数', function () {
    $contact = Contact::factory()->create([]);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 2,
        ]);

    foreach (range(1, 2) as $i) {
        ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Visitor,
            'kind' => MessageKind::Text,
            'content' => 'visitor unread #'.$i,
            'created_at' => now()->subMinutes(10 - $i),
            'updated_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation_list.0.id', $conversation->id)
            ->where('conversation_list.0.unread_count', 2)
            ->where('tab_counts.mine', 1)
        );

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/read')
        ->assertNoContent();

    expect($conversation->fresh()->unread_visitor_message_count)->toBe(0);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation_list.0.id', $conversation->id)
            ->where('conversation_list.0.unread_count', 0)
            ->where('tab_counts.mine', 0)
        );

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'new visitor message after read',
        'created_at' => now()->addSecond(),
        'updated_at' => now()->addSecond(),
    ]);

    $conversation->update(['unread_visitor_message_count' => 1]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation_list.0.id', $conversation->id)
            ->where('conversation_list.0.unread_count', 1)
            ->where('tab_counts.mine', 1)
        );
});

test('点击非本人会话不会更新已读位置', function () {
    $contact = Contact::factory()->create([]);
    $teammate = User::factory()->create();
    $this->attachSystem($teammate, $this->systemContext);

    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($teammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor message for colleague',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox?view=teammates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation_list.0.id', $conversation->id)
            ->where('conversation_list.0.unread_count', 0)
        );

    $this->actingAs($this->user)
        ->post('/admin/inbox/'.$conversation->id.'/read')
        ->assertNoContent();

    expect($conversation->fresh()->unread_visitor_message_count)->toBe(1);
});

test('tab_counts.pending会统计打开和teammate_pending会话且不受未读状态影响', function () {
    $contact = Contact::factory()->create([]);

    Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'assigned_user_id' => null,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'assigned_user_id' => null,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 1,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->closed()
        ->create([
            'inbox_status' => ConversationInboxStatus::TeammatePending,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab_counts.pending', 2)
        );
});

test('tab_counts.mine只统计分配给当前用户且有连续访客消息的打开会话', function () {
    $contact = Contact::factory()->create([]);
    $teammate = User::factory()->create();
    $this->attachSystem($teammate, $this->systemContext);

    $mineWithBacklog = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $mineWithBacklog->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'first teammate reply',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $mineWithBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor follow up after my reply',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $mineNoBacklog = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'waiting_for_visitor_reply' => true,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $mineNoBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor asked',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $mineNoBacklog->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'I replied last',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $otherTeammateBacklog = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($teammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $otherTeammateBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor still waiting',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $closedMine = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->closed()
        ->create([
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $closedMine->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor message on closed conversation',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab_counts.mine', 1)
        );
});

test('tab_counts.teammates不统计同事会话未读消息', function () {
    $contact = Contact::factory()->create([]);
    $teammate = User::factory()->create();
    $this->attachSystem($teammate, $this->systemContext);

    $teammateWithBacklog = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($teammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $teammateWithBacklog->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'colleague replied first',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $teammateWithBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor follow up after colleague reply',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $teammateNoBacklog = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($teammate)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $teammateNoBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor asked colleague',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $teammateNoBacklog->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'colleague answered last',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    Conversation::factory()
        ->forContact($contact)
        ->assignedTo($teammate)
        ->closed()
        ->create([]);

    $this->actingAs($this->user)
        ->get('/admin/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab_counts.teammates', 0)
        );
});

test('tab_counts.ai不统计非本人会话未读消息', function () {
    $contact = Contact::factory()->create([]);

    $aiWithBacklog = Conversation::factory()
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $aiWithBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor question for AI',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $visitorWaitingWithBacklog = Conversation::factory()
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $visitorWaitingWithBacklog->id,
        'role' => MessageRole::Ai,
        'kind' => MessageKind::Text,
        'content' => 'AI replied first',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $visitorWaitingWithBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor came back',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $aiNoBacklog = Conversation::factory()
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $aiNoBacklog->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'visitor asked',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::query()->create([
        'conversation_id' => $aiNoBacklog->id,
        'role' => MessageRole::Ai,
        'kind' => MessageKind::Text,
        'content' => 'AI answered last',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $assignedAi = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($this->user)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $assignedAi->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'should not count toward ai tab',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $teammatePending = Conversation::factory()
        ->forContact($contact)
        ->create([
            'assigned_user_id' => null,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'unread_visitor_message_count' => 1,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $teammatePending->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => 'should not count toward ai tab either',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab_counts.ai', 0)
        );
});

test('tab_counts限定在当前系统并忽略其他系统', function () {
    $otherSystem = SystemContext::factory()->create();
    $otherContact = Contact::factory()->create([]);

    Conversation::factory()
        ->forContact($otherContact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'assigned_user_id' => null,
        ]);

    $contact = Contact::factory()->create([]);
    Conversation::factory()
        ->forContact($contact)
        ->create([
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
            'assigned_user_id' => null,
        ]);

    $this->actingAs($this->user)
        ->get('/admin/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab_counts.pending', 2)
            ->where('tab_counts.ai', 0)
            ->where('tab_counts.mine', 0)
            ->where('tab_counts.teammates', 0)
        );
});
