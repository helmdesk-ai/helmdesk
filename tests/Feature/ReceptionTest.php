<?php

use App\Actions\Native\Reception\AppendVisitorMessageBridgeAction;
use App\Actions\Native\Reception\StartOrResumeReceptionSessionBridgeAction;
use App\Actions\Reception\AppendAiMessageAction;
use App\Actions\Reception\AppendVisitorMessageAction;
use App\Actions\Reception\ClaimConversationAction;
use App\Actions\Reception\HandleAiUnavailableAction;
use App\Actions\Reception\LoadConversationHistoryAction;
use App\Actions\Reception\ReleaseConversationToAiAction;
use App\Actions\Reception\RequestHandoffAction;
use App\Actions\Reception\StartOrResumeReceptionSessionAction;
use App\Actions\Reception\TransferConversationToTeammateAction;
use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Data\Reception\Plan\AutoMessagesConfigData;
use App\Data\Reception\Plan\ReceptionMessageTranslationConfigData;
use App\Enums\AiModelPurpose;
use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\Channel\Web\WebChannelVisitorIdentityMode;
use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\ConversationEntryMode;
use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Enums\Reception\ReceptionRoutingMode;
use App\Enums\ReceptionLanguage;
use App\Enums\UserOnlineStatus;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAutoMessageReceipt;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Models\TranslationProvider;
use App\Models\User;
use App\Services\Reception\ReceptionStateBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

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

/**
 * Seed 一套全局可用的接待 AI 模型（reception_chat + background_task）并返回接待主模型。
 *
 * 接待方案不再引用具体模型：运行时按 reception_chat 用途从全局池判断 AI 可用性。
 * $modelAttributes 兼容旧签名，可传 ['is_active' => false] 造一个停用模型，模拟 AI 不可用。
 *
 * @param  array<string, mixed>  $providerAttributes
 * @param  array<string, mixed>  $modelAttributes
 */
function createReceptionModel(array $providerAttributes = [], array $modelAttributes = []): AiModel
{
    $isActive = (bool) ($modelAttributes['is_active'] ?? true);
    $provider = makeUsableAiProvider($providerAttributes);

    $model = makeAiModel(AiModelPurpose::ReceptionChat, $provider, $isActive);
    makeAiModel(AiModelPurpose::BackgroundTask, $provider, $isActive);

    return $model;
}

/**
 * 创建一个已部署接待方案版本的 system + plan + version + channel 链路；
 * persona display name 可由 $personaDisplayName 指定，便于测试访客视角下 AI 名称展示。
 */
function createReceptionChannel(
    ?string $personaDisplayName = null,
    array $channelAttributes = [],
    ?AiModel $model = null,
    array $versionAttributes = [],
): Channel {
    $systemContext = SystemContext::factory()->create();
    User::factory()->create([
        'is_super_admin' => true,
        'online_status' => UserOnlineStatus::Online->value,
    ]);
    $model ??= createReceptionModel();
    $plan = ReceptionPlan::factory()->create([
        'name' => '接待方案-'.Str::lower(Str::random(6)),
    ]);
    // 翻译供应商由接待方案版本快照引用：默认给每个接待渠道挂一个可用供应商。
    $translationProvider = TranslationProvider::factory()->create();
    $baseSnapshot = ReceptionPlanVersion::factory()->definition()['snapshot_config'] ?? [];
    $baseSnapshot['auto_messages_config'] = receptionTestDisabledAutoMessagesConfig();
    $baseSnapshot['translation_config'] = receptionMessageTranslationConfig(['enabled' => false]);

    if (filled($personaDisplayName)) {
        $baseSnapshot['persona_config'] = array_merge(
            $baseSnapshot['persona_config'] ?? [],
            ['display_name' => $personaDisplayName],
        );
    }

    if (isset($versionAttributes['snapshot_config']) && is_array($versionAttributes['snapshot_config'])) {
        $versionAttributes['snapshot_config'] = array_replace_recursive($baseSnapshot, $versionAttributes['snapshot_config']);
    } else {
        $versionAttributes['snapshot_config'] = $baseSnapshot;
    }

    // 强制把供应商写入合并后的快照，避免测试覆盖 translation_config 时丢掉 provider_id。
    $versionAttributes['snapshot_config']['translation_config']['provider_id'] = $translationProvider->id;

    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->create($versionAttributes);

    $channel = Channel::factory()->create(array_merge([
        'reception_plan_id' => $plan->id,
        'reception_plan_version_id' => $version->id,
    ], $channelAttributes));

    return $channel;
}

/**
 * 构造接待方案快照中的自动回复配置。
 *
 * @param  array<string, array{enabled: bool, message: string}>  $overrides
 * @return array<string, array{enabled: bool, message: string}>
 */
function receptionAutoMessagesConfig(array $overrides): array
{
    return array_replace_recursive(AutoMessagesConfigData::DEFAULT_CONFIG, $overrides);
}

/**
 * 构造接待方案快照中的访客侧预设文案翻译配置。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function receptionMessageTranslationConfig(array $overrides): array
{
    return array_replace(ReceptionMessageTranslationConfigData::DEFAULT_CONFIG, $overrides);
}

/**
 * 构造不参与自动回复断言的接待方案快照配置。
 *
 * @return array<string, array{enabled: bool, message: string|null}>
 */
function receptionTestDisabledAutoMessagesConfig(): array
{
    return [
        'ai_welcome' => ['enabled' => false, 'message' => null],
        'teammate_joined' => ['enabled' => false, 'message' => null],
        'teammate_transferred' => ['enabled' => false, 'message' => null],
    ];
}

/**
 * 构造接待方案快照中的流程策略配置。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function receptionStrategyConfig(array $overrides): array
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

test('新会话会锁定渠道当前部署的接待方案版本', function () {
    $channel = createReceptionChannel();
    $initialVersion = ReceptionPlanVersion::query()->where('id', $channel->reception_plan_version_id)->firstOrFail();

    app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $conversation = Conversation::query()->firstOrFail();

    expect($conversation->reception_plan_version_id)->toBe($initialVersion->id);

    $newerVersion = ReceptionPlanVersion::factory()
        ->for($initialVersion->plan, 'plan')
        ->create(['version_number' => 2]);
    $channel->forceFill(['reception_plan_version_id' => $newerVersion->id])->save();

    $conversation->refresh();

    expect($conversation->reception_plan_version_id)->toBe($initialVersion->id);
});

test('自动回复会在 AI 接待新会话中写入欢迎语且保持幂等', function () {
    $channel = createReceptionChannel('AI 小海', versionAttributes: [
        'snapshot_config' => [
            'auto_messages_config' => receptionAutoMessagesConfig([
                'ai_welcome' => ['enabled' => true, 'message' => '您好，我是{{display_name}}。'],
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $message = ConversationMessage::query()->where('conversation_id', $conversation->id)->firstOrFail();

    expect($started->messages)->toHaveCount(1)
        ->and($started->messages[0]->content)->toBe('您好，我是AI 小海。')
        ->and($message->role)->toBe(MessageRole::Ai)
        ->and($message->sender_name)->toBe('AI 小海')
        ->and($message->payload['source'])->toBe('auto_message')
        ->and($message->payload['trigger'])->toBe(ConversationAutoMessageTrigger::AiWelcome->value)
        ->and($conversation->last_message_preview)->toBe('您好，我是AI 小海。')
        ->and($conversation->unread_agent_message_count)->toBe(1)
        ->and(ConversationAutoMessageReceipt::query()->where('conversation_id', $conversation->id)->count())->toBe(1);

    app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        $started->session_token,
        ConversationEntryMode::Standalone,
    );

    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and(ConversationAutoMessageReceipt::query()->where('conversation_id', $conversation->id)->count())->toBe(1);
});

test('自动回复翻译失败时默认不发送并记录客服事件', function () {
    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
        'translation.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key expired'],
        ], 401),
    ]);

    $channel = createReceptionChannel('AI 小海', channelAttributes: [
        'settings' => ChannelWebSettingsData::defaults([
            'default_visitor_locale' => ReceptionLanguage::English->value,
        ]),
    ], versionAttributes: [
        'snapshot_config' => [
            'translation_config' => receptionMessageTranslationConfig([
                'enabled' => true,
            ]),
            'auto_messages_config' => receptionAutoMessagesConfig([
                'ai_welcome' => ['enabled' => true, 'message' => '您好，我是{{display_name}}。'],
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        ['locale' => 'en'],
    );
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $event = ConversationEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('type', ConversationEventType::AutoMessageTranslationFailed)
        ->firstOrFail();
    $receipt = ConversationAutoMessageReceipt::query()
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();

    expect($started->messages)->toHaveCount(0)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(0)
        ->and($receipt->message_id)->toBeNull()
        ->and($event->payload)->toMatchArray([
            'trigger' => ConversationAutoMessageTrigger::AiWelcome->value,
            'mode' => AutoMessageTranslationFailureMode::Skip->value,
            'content' => '您好，我是AI 小海。',
        ]);
});

test('自动回复翻译失败时可按接待方案策略发送原文并记录客服事件', function () {
    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
        'translation.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key expired'],
        ], 401),
    ]);

    $channel = createReceptionChannel('AI 小海', channelAttributes: [
        'settings' => ChannelWebSettingsData::defaults([
            'default_visitor_locale' => ReceptionLanguage::English->value,
        ]),
    ], versionAttributes: [
        'snapshot_config' => [
            'translation_config' => receptionMessageTranslationConfig([
                'enabled' => true,
                'failure_mode' => AutoMessageTranslationFailureMode::SendOriginal->value,
            ]),
            'auto_messages_config' => receptionAutoMessagesConfig([
                'ai_welcome' => ['enabled' => true, 'message' => '您好，我是{{display_name}}。'],
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        ['locale' => 'en'],
    );
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();
    $event = ConversationEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('type', ConversationEventType::AutoMessageTranslationFailed)
        ->firstOrFail();

    expect($started->messages)->toHaveCount(1)
        ->and($started->messages[0]->content)->toBe('您好，我是AI 小海。')
        ->and($message->payload)->not->toHaveKey('translations')
        ->and($event->payload)->toMatchArray([
            'trigger' => ConversationAutoMessageTrigger::AiWelcome->value,
            'mode' => AutoMessageTranslationFailureMode::SendOriginal->value,
            'content' => '您好，我是AI 小海。',
        ]);
});

test('自动回复会在发送前写入访客可见内容', function () {
    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => 'Hello, I am AI Helm.', 'detectedSourceLanguage' => 'zh-CN'],
                ],
            ],
        ]),
    ]);

    $channel = createReceptionChannel('AI 小海', channelAttributes: [
        'settings' => ChannelWebSettingsData::defaults([
            'default_visitor_locale' => ReceptionLanguage::English->value,
        ]),
    ], versionAttributes: [
        'snapshot_config' => [
            'translation_config' => receptionMessageTranslationConfig([
                'enabled' => true,
            ]),
            'auto_messages_config' => receptionAutoMessagesConfig([
                'ai_welcome' => ['enabled' => true, 'message' => '您好，我是{{display_name}}。'],
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        ['locale' => 'en'],
    );
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();

    expect($started->messages)->toHaveCount(1)
        ->and($started->messages[0]->content)->toBe('Hello, I am AI Helm.')
        ->and($message->content)->toBe('Hello, I am AI Helm.')
        ->and($message->content_locale)->toBe('en')
        ->and($message->payload['translations']['zh-CN']['text'])->toBe('您好，我是AI 小海。')
        ->and(ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', ConversationEventType::AutoMessageTranslationFailed)->exists())->toBeFalse();
});

test('自动回复在人工优先待接进入 AI 接管后发送欢迎语', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => true,
                'unassigned_ai_takeover_timeout_seconds' => 0,
            ]),
            'auto_messages_config' => receptionAutoMessagesConfig([
                'ai_welcome' => ['enabled' => true, 'message' => 'AI 已接手。'],
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);

    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(0);

    $resumed = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        $started->session_token,
        ConversationEntryMode::Standalone,
    );

    $conversation->refresh();

    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling)
        ->and($resumed->messages)->toHaveCount(1)
        ->and($resumed->messages[0]->content)->toBe('AI 已接手。')
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(1);
});

test('自动回复会在客服接入和多次转接时按事件写入欢迎语', function () {
    $channel = createReceptionChannel(versionAttributes: [
        'snapshot_config' => [
            'auto_messages_config' => receptionAutoMessagesConfig([
                'teammate_joined' => ['enabled' => true, 'message' => '您好，我是{{teammate_name}}。'],
                'teammate_transferred' => ['enabled' => true, 'message' => '{{teammate_name}}已接手本次会话。'],
            ]),
        ],
    ]);
    $firstAgent = User::factory()->create(['name' => '一号客服']);
    $secondAgent = User::factory()->create(['name' => '二号客服']);
    $thirdAgent = User::factory()->create(['name' => '三号客服']);
    foreach ([$firstAgent, $secondAgent, $thirdAgent] as $agent) {
    }

    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $channel->reception_plan_version_id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammatePending,
        ]);

    $claimed = app(ClaimConversationAction::class)->handle($conversation, $firstAgent);
    $transferred = app(TransferConversationToTeammateAction::class)->handle($claimed, $firstAgent, $secondAgent);
    app(TransferConversationToTeammateAction::class)->handle($transferred, $secondAgent, $thirdAgent);

    $messages = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->orderBy('seq_no')
        ->get();

    expect($messages)->toHaveCount(3)
        ->and($messages[0]->content)->toBe('您好，我是一号客服。')
        ->and($messages[0]->payload['trigger'])->toBe(ConversationAutoMessageTrigger::TeammateJoined->value)
        ->and($messages[1]->content)->toBe('二号客服已接手本次会话。')
        ->and($messages[1]->payload['trigger'])->toBe(ConversationAutoMessageTrigger::TeammateTransferred->value)
        ->and($messages[2]->content)->toBe('三号客服已接手本次会话。')
        ->and($messages[2]->payload['trigger'])->toBe(ConversationAutoMessageTrigger::TeammateTransferred->value)
        ->and(ConversationAutoMessageReceipt::query()->where('conversation_id', $conversation->id)->count())->toBe(3);
});

test('自动回复在 AI 转人工时使用转接欢迎语', function () {
    $channel = createReceptionChannel(versionAttributes: [
        'snapshot_config' => [
            'auto_messages_config' => receptionAutoMessagesConfig([
                'teammate_joined' => ['enabled' => true, 'message' => '您好，我是{{teammate_name}}。'],
                'teammate_transferred' => ['enabled' => true, 'message' => '{{teammate_name}}已从 AI 接手。'],
            ]),
        ],
    ]);
    $agent = User::factory()->create(['name' => '人工客服']);

    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->create([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $channel->reception_plan_version_id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::AiHandling,
            'assigned_user_id' => null,
        ]);

    app(ClaimConversationAction::class)->handle($conversation, $agent);

    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();

    expect($message->content)->toBe('人工客服已从 AI 接手。')
        ->and($message->payload['trigger'])->toBe(ConversationAutoMessageTrigger::TeammateTransferred->value);
});

test('自动回复只把 AI 欢迎语交给 AI 上下文', function () {
    $conversation = Conversation::factory()->withReceptionPlanVersion()->create([
        'inbox_status' => ConversationInboxStatus::AiHandling,
    ]);

    ConversationMessage::factory()->visitorText()->forConversation($conversation)->create([
        'content' => '访客问题',
        'seq_no' => 1,
    ]);
    $aiWelcome = ConversationMessage::factory()->aiText()->forConversation($conversation)->create([
        'content' => 'AI 欢迎语',
        'seq_no' => 2,
        'payload' => [
            'source' => 'auto_message',
            'trigger' => ConversationAutoMessageTrigger::AiWelcome->value,
        ],
    ]);
    ConversationMessage::factory()->aiText()->forConversation($conversation)->create([
        'content' => '流程提示',
        'seq_no' => 3,
        'payload' => [
            'source' => 'auto_message',
            'trigger' => ConversationAutoMessageTrigger::TeammateJoined->value,
        ],
    ]);
    ConversationMessage::factory()->aiText()->forConversation($conversation)->create([
        'content' => '普通 AI 回复',
        'seq_no' => 4,
    ]);

    $history = app(LoadConversationHistoryAction::class)->handle($conversation);

    expect(collect($history)->pluck('content')->all())->toBe([
        '访客问题',
        'AI 欢迎语',
        '普通 AI 回复',
    ])
        ->and($history[1]['id'])->toBe((string) $aiWelcome->id);
});

test('暂停（软删除）的渠道拒绝新建访客会话', function () {
    $channel = createReceptionChannel();
    $channel->delete();

    expect(fn () => app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    ))->toThrow(GoneHttpException::class);

    expect(Conversation::query()->count())->toBe(0);
});

test('暂停的渠道仍允许已有会话继续消息往返', function () {
    $channel = createReceptionChannel();

    // 先在渠道仍在线时建立会话和拿到 session token
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );
    $sessionToken = $started->session_token;

    // 然后才暂停渠道
    $channel->delete();

    // 用同一个 session token resume 应仍能拿到原会话
    $resumed = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        $sessionToken,
        ConversationEntryMode::Standalone,
    );

    expect($resumed->session_token)->toBe($sessionToken)
        ->and($resumed->conversation_id)->toBe($started->conversation_id);

    // 仍可向已有会话追加访客消息
    $afterAppend = app(AppendVisitorMessageBridgeAction::class)->handle(
        $channel->code,
        $sessionToken,
        '渠道暂停后我还想继续聊',
    );

    expect($afterAppend->conversation_id)->toBe($started->conversation_id);
    expect(ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('content', '渠道暂停后我还想继续聊')
        ->exists())->toBeTrue();
});

test('启动接待时会在联系人上记录访客环境', function () {
    $channel = createReceptionChannel();

    $state = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        [
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
            'country' => 'US',
            'city' => 'New York',
        ],
    );

    $contact = Contact::query()->firstOrFail();

    expect($state->session_token)->not->toBeEmpty()
        ->and($contact->locale)->toBeNull()
        ->and($contact->timezone)->toBe('America/New_York')
        ->and($contact->country)->toBe('US')
        ->and($contact->city)->toBe('New York')
        ->and($contact->last_seen_at)->not->toBeNull();
});

test('原生接待桥接接受标量载荷并委托给类型化Action', function () {
    $channel = createReceptionChannel();

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
        ['timezone' => 'Asia/Shanghai'],
    );

    $state = app(AppendVisitorMessageBridgeAction::class)->handle(
        $channel->code,
        $started->session_token,
        'hello from native',
        ConversationEntryMode::Standalone->value,
        ['timezone' => 'Asia/Shanghai'],
        [null, '', 123],
    );

    $conversation = Conversation::query()->findOrFail($state->conversation_id);

    expect($conversation->entry_mode)
        ->toBe(ConversationEntryMode::Standalone)
        ->and($state->messages[0]->content)
        ->toBe('hello from native')
        ->and($state->inbox_status)
        ->toBe(ConversationInboxStatus::AiHandling->value);
});

test('原生访客桥接暴露同事处理状态供Go占位AI决策使用', function () {
    $channel = createReceptionChannel();

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $teammate = User::factory()->create();
    Conversation::query()->firstOrFail()->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    $state = app(AppendVisitorMessageBridgeAction::class)->handle(
        $channel->code,
        $started->session_token,
        'hello after teammate claimed',
        ConversationEntryMode::Standalone->value,
    );

    expect($state->inbox_status)
        ->toBe(ConversationInboxStatus::TeammateHandling->value)
        ->and($state->messages[0]->content)
        ->toBe('hello after teammate claimed');
});

test('原生访客消息入库时不触发客服侧翻译', function () {
    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
    ]);

    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
    );

    $teammate = User::factory()->create(['locale' => 'zh-CN']);
    Conversation::query()->firstOrFail()->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    app(AppendVisitorMessageBridgeAction::class)->handle(
        $channel->code,
        $started->session_token,
        'hello after teammate claimed',
        ConversationEntryMode::Standalone->value,
    );

    $message = ConversationMessage::query()
        ->where('role', MessageRole::Visitor)
        ->where('kind', MessageKind::Text)
        ->latest('seq_no')
        ->firstOrFail();

    expect($message->payload)->toBeNull();
});

test('访客端只展示消息正文', function () {
    $channel = createReceptionChannel();
    $contact = Contact::factory()->create([
        'locale' => 'en',
    ]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->for($channel)
        ->create([
            'visitor_locale' => 'en',
        ]);
    ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => '你好',
        'payload' => [
            'translations' => [
                'en' => ['text' => 'Hello'],
            ],
        ],
    ]);

    $state = ReceptionStateBuilder::build($channel, $conversation, 'session-token');

    expect($state->messages[0]->content)->toBe('你好');
});

test('原生接待桥接接受组件入口模式', function () {
    $channel = createReceptionChannel();

    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Widget->value,
    );

    $state = app(AppendVisitorMessageBridgeAction::class)->handle(
        $channel->code,
        $started->session_token,
        'hello from widget',
        ConversationEntryMode::Widget->value,
    );

    $conversation = Conversation::query()->findOrFail($state->conversation_id);

    expect($conversation->entry_mode)
        ->toBe(ConversationEntryMode::Widget)
        ->and($state->messages[0]->content)
        ->toBe('hello from widget');
});

test('原生接待桥接拒绝无效入口模式', function () {
    $channel = createReceptionChannel();

    app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        'not-a-valid-entry-mode',
    );
})->throws(ValidationException::class);

test('原生接待桥接拒绝无效时区', function () {
    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionBridgeAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone->value,
        ['timezone' => 'Asia/Shanghai'],
    );

    app(AppendVisitorMessageBridgeAction::class)->handle(
        $channel->code,
        $started->session_token,
        'hello from native',
        ConversationEntryMode::Standalone->value,
        ['timezone' => 'Invalid/Timezone'],
        [],
    );
})->throws(ValidationException::class);

test('访客消息刷新拒绝无效时区', function () {
    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        ['timezone' => 'Asia/Shanghai'],
    );
    $contact = Contact::query()->firstOrFail();
    $contact->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
    $previousLastSeen = $contact->last_seen_at->copy();

    expect(fn () => app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        'hello',
        ConversationEntryMode::Standalone,
        ['timezone' => 'Not/A_Timezone'],
    ))->toThrow(ValidationException::class);

    $contact->refresh();

    expect($contact->timezone)->toBe('Asia/Shanghai')
        ->and($contact->last_seen_at->equalTo($previousLastSeen))->toBeTrue();
});

test('访客回复等待标记会由AI回复设置并由访客消息清除', function () {
    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        'hello',
    );

    expect(Conversation::query()->firstOrFail()->unread_visitor_message_count)->toBe(1);

    $aiState = app(AppendAiMessageAction::class)->handle(
        Conversation::query()->firstOrFail(),
        'hello, how can I help?',
    );

    expect(Conversation::query()->firstOrFail()->waiting_for_visitor_reply)->toBeTrue()
        ->and(Conversation::query()->firstOrFail()->unread_visitor_message_count)->toBe(0)
        ->and($aiState->toArray())->not->toHaveKey('inbox_status')
        ->and($aiState->toArray())->not->toHaveKey('inbox_status_label')
        ->and($aiState->toArray())->not->toHaveKey('waiting_for_visitor_reply')
        ->and($aiState->toArray())->not->toHaveKey('waiting_for_visitor_reply_label');

    $visitorState = app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        'I have a question',
    );

    expect(Conversation::query()->firstOrFail()->waiting_for_visitor_reply)->toBeFalse()
        ->and(Conversation::query()->firstOrFail()->unread_visitor_message_count)->toBe(1)
        ->and($visitorState->toArray())->not->toHaveKey('inbox_status')
        ->and($visitorState->toArray())->not->toHaveKey('inbox_status_label')
        ->and($visitorState->toArray())->not->toHaveKey('waiting_for_visitor_reply')
        ->and($visitorState->toArray())->not->toHaveKey('waiting_for_visitor_reply_label');
});

test('访客可以发送附件只图片消息和下载保持在会话范围内', function () {
    Storage::fake('local');

    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );
    $thumbnailKey = 'attachments/conversation_image/photo_thumb.webp';

    $attachment = Attachment::factory()->create([
        'object_key' => 'attachments/conversation_image/photo.png',
        'original_name' => 'photo.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 10,
        'visibility' => 'private',
        'purpose' => 'conversation_image',
        'status' => 'uploaded',
        'metadata' => ['thumbnail_key' => $thumbnailKey],
    ]);
    Storage::disk('local')->put($attachment->object_key, 'fake-image');
    Storage::disk('local')->put($thumbnailKey, 'fake-thumbnail');
    AttachmentUpload::factory()->create([
        'attachment_id' => $attachment->id,
        'storage_profile_id' => $attachment->storage_profile_id,
        'object_key' => $attachment->object_key,
        'expected_name' => $attachment->original_name,
        'expected_mime_type' => $attachment->mime_type,
        'expected_byte_size' => $attachment->byte_size,
        'session_token_hash' => hash('sha256', $started->session_token),
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $state = app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        '',
        ConversationEntryMode::Standalone,
        [],
        [(string) $attachment->id],
    );

    $message = ConversationMessage::query()->firstOrFail();

    expect($message->kind)->toBe(MessageKind::Image)
        ->and($message->content)->toBeNull()
        ->and($message->attachments()->count())->toBe(1)
        ->and($state->messages[0]->attachments[0]->id)->toBe((string) $attachment->id)
        ->and($state->messages[0]->attachments[0]->url)->toStartWith('/attachments/dl?')
        ->and($state->messages[0]->attachments[0]->url)->toContain('sig=')
        ->and($state->messages[0]->attachments[0]->preview_url)->toStartWith('/attachments/dl?')
        ->and($state->messages[0]->attachments[0]->preview_url)->toContain('mime=image%2Fwebp')
        ->and($state->messages[0]->attachments[0]->preview_url)->toContain('sig=');
});

test('访客附件上传保持在会话范围内当浏览器已认证时', function () {
    Storage::fake('local');

    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent('note.txt', 'hello attachment');

    $createResponse = $this->actingAs($user)
        ->withHeader('X-Helmdesk-Visitor-Token', $started->session_token)
        ->postJson('/api/visitor/attachments/uploads', [
            'purpose' => 'conversation_file',
            'file_name' => 'note.txt',
            'mime_type' => 'text/plain',
            'byte_size' => $file->getSize(),
            'context' => [
                'channel_code' => $channel->code,
                'session_token' => $started->session_token,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'proxy');

    $uploadId = $createResponse->json('upload.id');
    $attachmentId = $createResponse->json('attachment.id');

    $this->actingAs($user)
        ->withHeader('X-Helmdesk-Visitor-Token', $started->session_token)
        ->post('/api/visitor/attachments/uploads/'.$uploadId.'/proxy', ['file' => $file])
        ->assertOk();

    $this->actingAs($user)
        ->withHeader('X-Helmdesk-Visitor-Token', $started->session_token)
        ->postJson('/api/visitor/attachments/uploads/'.$uploadId.'/complete')
        ->assertOk()
        ->assertJsonPath('attachment.status', 'uploaded');

    $upload = AttachmentUpload::query()->findOrFail($uploadId);
    $attachment = Attachment::query()->findOrFail($attachmentId);

    expect($upload->created_by_user_id)->toBeNull()
        ->and($upload->session_token_hash)->toBe(hash('sha256', $started->session_token))
        ->and($attachment->uploaded_by_user_id)->toBeNull();

    $state = app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        '',
        ConversationEntryMode::Standalone,
        [],
        [$attachmentId],
    );
    $message = ConversationMessage::query()->firstOrFail();

    expect($message->kind)->toBe(MessageKind::File)
        ->and($message->attachments()->count())->toBe(1)
        ->and($state->messages[0]->attachments[0]->id)->toBe($attachmentId);
});

test('访客可以一次发送多附件并按 B 端规则拆成独立消息', function () {
    Storage::fake('local');

    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $attachmentIds = collect(range(1, 3))
        ->map(function (int $index) use ($started): string {
            $key = 'attachments/conversation_image/photo'.$index.'.png';
            $thumbKey = 'attachments/conversation_image/photo'.$index.'_thumb.webp';

            $attachment = Attachment::factory()->create([
                'object_key' => $key,
                'original_name' => 'photo'.$index.'.png',
                'mime_type' => 'image/png',
                'extension' => 'png',
                'byte_size' => 10,
                'visibility' => 'private',
                'purpose' => 'conversation_image',
                'status' => 'uploaded',
                'metadata' => ['thumbnail_key' => $thumbKey],
            ]);
            Storage::disk('local')->put($attachment->object_key, 'fake-image');
            Storage::disk('local')->put($thumbKey, 'fake-thumbnail');
            AttachmentUpload::factory()->create([
                'attachment_id' => $attachment->id,
                'storage_profile_id' => $attachment->storage_profile_id,
                'object_key' => $attachment->object_key,
                'expected_name' => $attachment->original_name,
                'expected_mime_type' => $attachment->mime_type,
                'expected_byte_size' => $attachment->byte_size,
                'session_token_hash' => hash('sha256', $started->session_token),
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return (string) $attachment->id;
        })
        ->all();

    $state = app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        '附件来啦',
        ConversationEntryMode::Standalone,
        [],
        $attachmentIds,
    );

    $messages = ConversationMessage::query()
        ->where('conversation_id', $state->conversation_id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($messages)->toHaveCount(4)
        ->and($messages[0]->kind)->toBe(MessageKind::Text)
        ->and($messages[0]->content)->toBe('附件来啦')
        ->and($messages[0]->attachments()->count())->toBe(0)
        ->and($messages[1]->kind)->toBe(MessageKind::Image)
        ->and($messages[1]->content)->toBeNull()
        ->and($messages[1]->attachments()->count())->toBe(1)
        ->and($messages[2]->attachments()->count())->toBe(1)
        ->and($messages[3]->attachments()->count())->toBe(1)
        ->and(collect($state->messages)->map(fn ($message) => $message->content)->all())
        ->toBe(['附件来啦', '', '', '']);
});

test('访客一次发送附件超过上限会被拒绝', function () {
    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $attachmentIds = array_fill(0, 11, '01jxxxxxxxxxxxxxxxxxxxxxxx');

    expect(fn () => app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        'too many',
        ConversationEntryMode::Standalone,
        [],
        $attachmentIds,
    ))->toThrow(ValidationException::class);
});

test('接待状态包含之前已关闭会话消息用于同一联系人频道', function () {
    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $firstConversation = Conversation::query()->findOrFail($started->conversation_id);
    ConversationMessage::factory()->visitorText()->forConversation($firstConversation)->create([
        'content' => 'old visitor question',
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);
    $firstConversation->update([
        'status' => ConversationStatus::Closed,
        'closed_at' => now()->subMinutes(10),
    ]);

    $otherChannel = Channel::factory()->create([
    ]);
    $otherChannelConversation = Conversation::factory()
        ->forContact($firstConversation->contact)
        ->create([
            'channel_id' => $otherChannel->id,
        ]);
    ConversationMessage::factory()->visitorText()->forConversation($otherChannelConversation)->create([
        'content' => 'other channel question',
        'created_at' => now()->subMinutes(15),
        'updated_at' => now()->subMinutes(15),
    ]);

    $secondStarted = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        $started->session_token,
        ConversationEntryMode::Standalone,
    );
    $secondConversation = Conversation::query()->findOrFail($secondStarted->conversation_id);
    ConversationMessage::factory()->visitorText()->forConversation($secondConversation)->create([
        'content' => 'current visitor question',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $state = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        $started->session_token,
        ConversationEntryMode::Standalone,
    );

    expect($state->conversation_id)->toBe($secondConversation->id)
        ->and(collect($state->messages)->map(fn ($message) => $message->content)->all())
        ->toBe(['old visitor question', 'current visitor question']);
});

test('人工可用时转交请求进入共享队列且不分配同事', function () {
    $channel = createReceptionChannel();
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );
    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $started->session_token,
        '请转人工',
    );
    $visitorMessage = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Visitor)
        ->firstOrFail();

    $decision = app(RequestHandoffAction::class)->handle(
        Conversation::query()->firstOrFail(),
        'needs_human',
        (string) $visitorMessage->id,
    );

    $conversation = Conversation::query()->firstOrFail();
    $notice = __('reception.defaults.handoff_available_notice');

    expect($decision->accepted)->toBeTrue()
        ->and($decision->notice)->toBe($notice)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending)
        ->and($conversation->assigned_user_id)->toBeNull()
        ->and((string) ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Ai)
            ->where('content', $notice)
            ->firstOrFail()->quoted_message_id)->toBe((string) $visitorMessage->id)
        ->and(ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', 'handoff_requested')->exists())->toBeTrue();
});

test('转人工可用提示会在发送前翻译成访客语言', function () {
    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => 'A teammate is being connected. Please wait.', 'detectedSourceLanguage' => 'zh-CN'],
                ],
            ],
        ]),
    ]);

    $channel = createReceptionChannel(null, channelAttributes: [
        'settings' => ChannelWebSettingsData::defaults([
            'default_visitor_locale' => ReceptionLanguage::English->value,
        ]),
    ], versionAttributes: [
        'snapshot_config' => [
            'translation_config' => receptionMessageTranslationConfig([
                'enabled' => true,
            ]),
            'strategy_config' => receptionStrategyConfig([
                'handoff_available_notice' => '已为您转接人工客服，请稍等。',
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        ['locale' => 'en'],
    );
    app(AppendVisitorMessageAction::class)->handle($channel->code, $started->session_token, '请转人工');
    $visitorMessage = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Visitor)
        ->firstOrFail();

    $decision = app(RequestHandoffAction::class)->handle(
        Conversation::query()->firstOrFail(),
        'needs_human',
        (string) $visitorMessage->id,
    );

    $conversation = Conversation::query()->firstOrFail();
    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Ai)
        ->where('content', 'A teammate is being connected. Please wait.')
        ->firstOrFail();

    expect($decision->accepted)->toBeTrue()
        ->and($decision->notice)->toBe('A teammate is being connected. Please wait.')
        ->and($conversation->last_message_preview)->toBe('A teammate is being connected. Please wait.')
        ->and($message->content_locale)->toBe('en')
        ->and($message->payload['translations']['zh-CN']['text'])->toBe('已为您转接人工客服，请稍等。')
        ->and((string) $message->quoted_message_id)->toBe((string) $visitorMessage->id);
});

test('非营业时间提示会在发送前翻译成访客语言', function () {
    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => 'It is outside support hours. AI will keep helping you.', 'detectedSourceLanguage' => 'zh-CN'],
                ],
            ],
        ]),
    ]);
    $schedule = [];
    for ($day = 1; $day <= 7; $day++) {
        $schedule[] = ['day' => $day, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'];
    }
    $channel = createReceptionChannel(null, channelAttributes: [
        'settings' => ChannelWebSettingsData::defaults([
            'default_visitor_locale' => ReceptionLanguage::English->value,
        ]),
    ], versionAttributes: [
        'snapshot_config' => [
            'translation_config' => receptionMessageTranslationConfig([
                'enabled' => true,
            ]),
            'strategy_config' => receptionStrategyConfig([
                'business_hours' => [
                    'timezone' => 'UTC',
                    'outside_hours_notice' => '当前非人工服务时间，AI 将继续为您服务。',
                    'schedule' => $schedule,
                ],
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        ['locale' => 'en'],
    );

    $decision = app(RequestHandoffAction::class)->handle(
        Conversation::query()->firstOrFail(),
        'needs_human',
    );

    $message = ConversationMessage::query()
        ->where('conversation_id', $started->conversation_id)
        ->where('role', MessageRole::Ai)
        ->where('content', 'It is outside support hours. AI will keep helping you.')
        ->firstOrFail();

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reason)->toBe('outside_business_hours')
        ->and($decision->notice)->toBe('It is outside support hours. AI will keep helping you.')
        ->and($message->content_locale)->toBe('en')
        ->and($message->payload['translations']['zh-CN']['text'])->toBe('当前非人工服务时间，AI 将继续为您服务。');
});

test('同事第一个接待开始于同事队列且会话锁定接待方案版本', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => false,
            ]),
        ],
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);

    $conversation = Conversation::query()->firstOrFail();

    expect($conversation->reception_plan_version_id)->toBe($channel->reception_plan_version_id)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);
});

test('同事优先排队且未启用未分配接管时保持人工待接', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => false,
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);

    expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);

    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'hello');
})->throws(BusinessException::class);

test('同事优先接待即使未配置未分配接管仍可手动释放给AI', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => false,
            ]),
            'auto_messages_config' => receptionAutoMessagesConfig([
                'ai_welcome' => ['enabled' => true, 'message' => 'AI 欢迎回来。'],
            ]),
        ],
    ]);
    $user = User::factory()->create();
    $contact = Contact::factory()->create([]);
    $conversation = Conversation::factory()
        ->forContact($contact)
        ->assignedTo($user)
        ->create([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $channel->reception_plan_version_id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
        ]);

    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '请继续处理',
    ]);

    $released = app(ReleaseConversationToAiAction::class)->handle($conversation, $user);
    $publishedEvents = collect(Http::recorded())
        ->map(fn (array $record): ?string => $record[0]['data']['event'] ?? null)
        ->filter()
        ->values()
        ->all();

    expect($released->assigned_user_id)->toBeNull()
        ->and($released->inbox_status)->toBe(ConversationInboxStatus::AiHandling)
        ->and($publishedEvents)->toBe([
            'ai_message_created',
            'ai_message_created',
            'conversation_released_to_ai',
            'conversation_released_to_ai',
        ])
        ->and(ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Ai)
            ->where('content', 'AI 欢迎回来。')
            ->exists())->toBeTrue();
});

test('同事优先接待会在配置超时后让 AI 接管', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => true,
                'unassigned_ai_takeover_timeout_seconds' => 0,
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);

    expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);

    $conversation = Conversation::query()->firstOrFail();

    expect($conversation->reception_plan_version_id)->toBe($channel->reception_plan_version_id)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling);
});

test('人工待接会话在没有人工可接待时由 AI 接管', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => false,
            ]),
        ],
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);

    expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);

    $teammate = User::query()->firstOrFail();
    $teammate->update([
        'online_status' => UserOnlineStatus::Offline->value,
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);

    expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::AiHandling);
});

test('人工待接会话进入非营业时间后由 AI 接管', function () {
    $schedule = [];
    for ($day = 1; $day <= 7; $day++) {
        $schedule[] = ['day' => $day, 'enabled' => true, 'open' => '09:00', 'close' => '18:00'];
    }

    try {
        Carbon::setTestNow('2026-05-26 10:00:00');

        $channel = createReceptionChannel(null, versionAttributes: [
            'snapshot_config' => [
                'strategy_config' => receptionStrategyConfig([
                    'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                    'unassigned_ai_takeover_enabled' => false,
                    'business_hours' => [
                        'timezone' => 'UTC',
                        'outside_hours_notice' => '当前非人工服务时间，AI 将继续为您服务。',
                        'schedule' => $schedule,
                    ],
                ]),
            ],
        ]);

        $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);

        expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);

        Carbon::setTestNow('2026-05-26 20:00:00');

        app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);

        expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::AiHandling);
    } finally {
        Carbon::setTestNow();
    }
});

test('AI 优先接待会在同事未响应时让 AI 接管', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::AiFirst->value,
                'teammate_no_response_ai_takeover_enabled' => true,
                'teammate_no_response_ai_takeover_timeout_seconds' => 0,
            ]),
        ],
    ]);
    $user = User::factory()->create();

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $conversation->update([
        'assigned_user_id' => $user->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ]);
    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Text,
        'content' => '还在吗？',
        'created_at' => now()->subMinute(),
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);

    $conversation->refresh();

    expect($conversation->assigned_user_id)->toBeNull()
        ->and($conversation->reception_plan_version_id)->toBe($channel->reception_plan_version_id)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling);
});

test('人工已接待会话在客服离线时保持人工接待', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'teammate_no_response_ai_takeover_enabled' => false,
            ]),
        ],
    ]);
    $teammate = User::query()->firstOrFail();

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $conversation->update([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ]);

    $teammate->update([
        'online_status' => UserOnlineStatus::Offline->value,
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);

    $conversation->refresh();

    expect((string) $conversation->assigned_user_id)->toBe((string) $teammate->id)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling);
});

test('人工已接待会话在非营业时间保持人工接待', function () {
    $schedule = [];
    for ($day = 1; $day <= 7; $day++) {
        $schedule[] = ['day' => $day, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'];
    }

    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'teammate_no_response_ai_takeover_enabled' => false,
                'business_hours' => [
                    'timezone' => 'UTC',
                    'outside_hours_notice' => '当前非人工服务时间，AI 将继续为您服务。',
                    'schedule' => $schedule,
                ],
            ]),
        ],
    ]);
    $teammate = User::query()->firstOrFail();

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $conversation->update([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);

    $conversation->refresh();

    expect((string) $conversation->assigned_user_id)->toBe((string) $teammate->id)
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammateHandling);
});

test('AI 优先接待在接待默认模型失效时降级为人工待接而不是 404', function () {
    $systemContext = SystemContext::factory()->create();
    $model = createReceptionModel([], ['is_active' => false]);
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::AiFirst->value,
            ]),
        ],
    ], model: $model);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);

    $conversation = Conversation::query()->firstOrFail();

    expect($started->session_token)->not->toBeEmpty()
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);
});

test('同事优先未分配接管在默认模型失效时保持人工待接', function () {
    $systemContext = SystemContext::factory()->create();
    $model = createReceptionModel([], ['is_active' => false]);
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => true,
                'unassigned_ai_takeover_timeout_seconds' => 0,
            ]),
        ],
    ], model: $model);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);

    expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);

    expect(Conversation::query()->firstOrFail()->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);
});

test('实际接待身份使用 persona 名称', function () {
    $user = User::factory()->create(['name' => '内部客服', 'nickname' => '对外客服']);
    $channel = createReceptionChannel('AI 小助手', [
        'settings' => ChannelWebSettingsData::defaults([
            'visitor_interface' => [
                'visitor_identity_mode' => WebChannelVisitorIdentityMode::ActualReceptionist->value,
            ],
        ]),
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Ai,
        'kind' => MessageKind::Text,
        'content' => 'ai message',
    ]);
    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => $user->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'teammate message',
    ]);

    $state = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);
    $senders = collect($state->messages)->pluck('sender_name', 'content');

    expect($senders['ai message'])->toBe('AI 小助手')
        ->and($senders['teammate message'])->toBe('对外客服');

    $user->update(['nickname' => null]);

    $state = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);
    $senders = collect($state->messages)->pluck('sender_name', 'content');

    expect($senders['ai message'])->toBe('AI 小助手')
        ->and($senders['teammate message'])->toBe('内部客服');
});

test('统一服务身份会隐藏实际 AI 和同事名称', function () {
    $user = User::factory()->create(['name' => '内部客服']);
    $channel = createReceptionChannel('AI 小助手', [
        'settings' => ChannelWebSettingsData::defaults([
            'visitor_interface' => [
                'visitor_identity_mode' => WebChannelVisitorIdentityMode::UnifiedService->value,
                'service_display_name' => '统一客服',
            ],
        ]),
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone);
    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Ai,
        'kind' => MessageKind::Text,
        'content' => 'ai message',
    ]);
    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => $user->id,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'teammate message',
    ]);

    $state = app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, $started->session_token, ConversationEntryMode::Standalone);
    $senders = collect($state->messages)->pluck('sender_name', 'content');

    expect($state->assistant_name)->toBe('统一客服')
        ->and($senders['ai message'])->toBe('统一客服')
        ->and($senders['teammate message'])->toBe('统一客服');
});

test('AI 不可用兜底文案会在发送前翻译成访客语言', function () {
    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => 'AI is temporarily unavailable. A teammate will help you shortly.', 'detectedSourceLanguage' => 'zh-CN'],
                ],
            ],
        ]),
    ]);

    $channel = createReceptionChannel(null, channelAttributes: [
        'settings' => ChannelWebSettingsData::defaults([
            'default_visitor_locale' => ReceptionLanguage::English->value,
        ]),
    ], versionAttributes: [
        'snapshot_config' => [
            'translation_config' => receptionMessageTranslationConfig([
                'enabled' => true,
            ]),
        ],
    ]);
    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        ['locale' => 'en'],
    );
    $conversation = Conversation::query()->findOrFail($started->conversation_id);

    $result = app(HandleAiUnavailableAction::class)->handle(
        $conversation,
        '很抱歉，AI 暂时不可用，正在为您转接人工客服。',
    );

    $conversation->refresh();
    $message = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Ai)
        ->where('content', 'AI is temporarily unavailable. A teammate will help you shortly.')
        ->firstOrFail();

    expect($result['handled'])->toBeTrue()
        ->and($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending)
        ->and($conversation->last_message_preview)->toBe('AI is temporarily unavailable. A teammate will help you shortly.')
        ->and($message->content_locale)->toBe('en')
        ->and($message->payload['translations']['zh-CN']['text'])->toBe('很抱歉，AI 暂时不可用，正在为您转接人工客服。');
});

test('AI 不可用冷却期内保持人工待接状态', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => true,
                'unassigned_ai_takeover_timeout_seconds' => 0,
            ]),
        ],
    ]);
    User::query()->update(['online_status' => UserOnlineStatus::Offline->value]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $conversation->update(['inbox_status' => ConversationInboxStatus::TeammatePending]);
    ConversationEvent::query()->create([
        'conversation_id' => $conversation->id,
        'type' => ConversationEventType::HandoffRequested,
        'payload' => ['reason' => 'ai_unavailable', 'actor_kind' => 'system'],
        'created_at' => now(),
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        $started->session_token,
        ConversationEntryMode::Standalone,
    );

    $conversation->refresh();

    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::TeammatePending);
});

test('AI 不可用冷却期过期后重新进入 AI 接待', function () {
    $channel = createReceptionChannel(null, versionAttributes: [
        'snapshot_config' => [
            'strategy_config' => receptionStrategyConfig([
                'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
                'unassigned_ai_takeover_enabled' => true,
                'unassigned_ai_takeover_timeout_seconds' => 0,
            ]),
        ],
    ]);
    User::query()->update(['online_status' => UserOnlineStatus::Offline->value]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    $conversation = Conversation::query()->findOrFail($started->conversation_id);
    $conversation->update(['inbox_status' => ConversationInboxStatus::TeammatePending]);
    ConversationEvent::query()->create([
        'conversation_id' => $conversation->id,
        'type' => ConversationEventType::HandoffRequested,
        'payload' => ['reason' => 'ai_unavailable', 'actor_kind' => 'system'],
        'created_at' => now()->subSeconds(301),
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        $started->session_token,
        ConversationEntryMode::Standalone,
    );

    $conversation->refresh();

    expect($conversation->inbox_status)->toBe(ConversationInboxStatus::AiHandling);
});
