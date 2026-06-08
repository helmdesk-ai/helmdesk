<?php

use App\Actions\Translation\TranslateConversationMessageAction;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Enums\MessageTranslationOutcome;
use App\Enums\ReceptionLanguage;
use App\Jobs\Inbox\TranslateInboxConversationMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlanVersion;
use App\Models\TranslationProvider;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use App\Services\Translation\Exceptions\TranslationException;
use App\Services\Translation\TranslationProviderPool;
use App\Services\Translation\TranslationResult;
use App\Services\Translation\TranslatorContract;
use App\Services\Translation\TranslatorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

function createFakeTranslatorManager(
    string $translatedText = 'translated text',
    string $detectedSourceLang = 'en',
    ?TranslationException $exception = null,
): TranslatorManager {
    $fakeDriver = new class($translatedText, $detectedSourceLang, $exception) implements TranslatorContract
    {
        public function __construct(
            private readonly string $translatedText,
            private readonly string $detectedSourceLang,
            private readonly ?TranslationException $exception,
        ) {}

        public function translate(string $text, string $sourceLang, string $targetLang, array $options = []): TranslationResult
        {
            if ($this->exception !== null) {
                throw $this->exception;
            }

            return new TranslationResult(
                text: $this->translatedText,
                source_lang: $sourceLang === 'auto' ? $this->detectedSourceLang : $sourceLang,
                target_lang: $targetLang,
                provider_slug: 'fake-provider',
                model: null,
                latency_ms: 50,
                char_count: mb_strlen($text),
            );
        }
    };

    $manager = Mockery::mock(TranslatorManager::class);
    $manager->shouldReceive('driverFor')->andReturn($fakeDriver);

    return $manager;
}

function buildTranslationChannel(): Channel
{
    return Channel::factory()->create();
}

/**
 * 在系统下创建一个已启用且凭据完整的全局翻译供应商，让轮询池有可用供应商；
 * 同时产出一个启用了访客侧翻译的接待方案版本，返回版本 ID 供会话挂载（运行时不再依赖它解析供应商）。
 */
function provisionTranslationPlanVersion(): string
{
    TranslationProvider::factory()->create();

    $version = ReceptionPlanVersion::factory()->create();
    $snapshot = $version->snapshot_config;
    $snapshot['translation_config'] = [
        'enabled' => true,
        'failure_mode' => 'skip',
    ];
    $version->update(['snapshot_config' => $snapshot]);

    return (string) $version->id;
}

beforeEach(function () {
    $this->user = $this->createUserWithSystem(['locale' => 'zh-CN']);
});

// ---------------------------------------------------------------------------
// 访客消息翻译
// ---------------------------------------------------------------------------

it('访客消息翻译成客服语言并写入 payload', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => null]);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    $manager = createFakeTranslatorManager('你好', 'en');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    $payload = $message->fresh()->payload;
    expect($payload)->toHaveKey('translations')
        ->and($payload['translations'])->toHaveKey('zh-CN')
        ->and($payload['translations']['zh-CN']['text'])->toBe('你好')
        ->and($payload['translations']['zh-CN']['source_lang'])->toBe('en')
        ->and($payload['translations']['zh-CN']['target_lang'])->toBe('zh-CN')
        ->and($message->fresh()->content_locale)->toBe('en');
});

it('访客消息使用最新客服语言作为翻译目标', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => null]);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $conversation->load('assignedUser');
    $this->user->newQuery()->whereKey($this->user->id)->update(['locale' => 'en']);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => '你好']);

    $manager = createFakeTranslatorManager('Hello', 'zh-CN');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($message->fresh()->payload['translations'])->toHaveKey('en');
});

it('未分配会话的访客消息不会自动翻译', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => null]);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => null,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    $manager = Mockery::mock(TranslatorManager::class);
    $manager->shouldNotReceive('driverFor');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($message->fresh()->payload)->toBeNull();
});

it('访客消息可手动翻译到指定客服语言', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => null]);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => null,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    $manager = createFakeTranslatorManager('こんにちは', 'en');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handleForTargetLang($message, $conversation, $channel, 'ja');

    expect($message->fresh()->payload['translations']['ja']['text'])->toBe('こんにちは');
});

// ---------------------------------------------------------------------------
// 客服语言补翻
// ---------------------------------------------------------------------------

it('自动翻译只处理访客消息', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'Hello',
        'content_locale' => 'en',
        'sender_user_id' => $this->user->id,
    ]);

    $manager = Mockery::mock(TranslatorManager::class);
    $manager->shouldNotReceive('driverFor');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($message->fresh()->payload)->toBeNull();
});

it('客服消息可手动补翻成当前客服语言', function () {
    $channel = Channel::factory()->create();
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => null]);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'Hello',
        'content_locale' => ReceptionLanguage::English->value,
        'sender_user_id' => $this->user->id,
    ]);

    $manager = createFakeTranslatorManager('你好', 'en');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handleForTargetLang($message, $conversation, $channel, 'zh-CN');

    expect($message->fresh()->payload['translations']['zh-CN']['text'])->toBe('你好');
});

it('相同供应商目标语言和正文的翻译会命中缓存', function () {
    Cache::flush();

    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => 'en']);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $firstMessage = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);
    $secondMessage = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    $driver = new class implements TranslatorContract
    {
        public function translate(string $text, string $sourceLang, string $targetLang, array $options = []): TranslationResult
        {
            return new TranslationResult(
                text: '你好',
                source_lang: 'en',
                target_lang: $targetLang,
                provider_slug: 'fake-provider',
                model: null,
                latency_ms: 50,
                char_count: mb_strlen($text),
            );
        }
    };
    $manager = Mockery::mock(TranslatorManager::class);
    $manager->shouldReceive('driverFor')->once()->andReturn($driver);
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));

    $action->handle($firstMessage, $conversation, $channel);
    $action->handle($secondMessage, $conversation, $channel);

    expect($firstMessage->fresh()->payload['translations']['zh-CN']['text'])->toBe('你好')
        ->and($secondMessage->fresh()->payload['translations']['zh-CN']['text'])->toBe('你好');
});

// ---------------------------------------------------------------------------
// AI 消息翻译
// ---------------------------------------------------------------------------

it('AI 消息可手动补翻成当前客服语言', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->aiText()->create([
        'content' => 'Hello, how can I help you?',
        'content_locale' => 'en',
    ]);

    $manager = createFakeTranslatorManager('你好，有什么可以帮你的？', 'en');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handleForTargetLang($message, $conversation, $channel, 'zh-CN');

    $payload = $message->fresh()->payload;
    expect($payload['translations']['zh-CN']['text'])->toBe('你好，有什么可以帮你的？');
});

it('手动补翻源语言已匹配目标语言时返回跳过结果', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => '你好',
        'content_locale' => 'zh-CN',
    ]);

    $manager = Mockery::mock(TranslatorManager::class);
    $manager->shouldNotReceive('driverFor');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));

    expect($action->handleForTargetLangWithOutcome($message, $conversation, $channel, 'zh-CN'))
        ->toBe(MessageTranslationOutcome::Skipped);
});

it('补翻任务跳过时不发送失败通知', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => '你好',
        'content_locale' => 'zh-CN',
    ]);

    $manager = Mockery::mock(TranslatorManager::class);
    $manager->shouldNotReceive('driverFor');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $notifier = Mockery::mock(ReceptionRealtimeNotifier::class);
    $notifier->shouldNotReceive('conversationChanged');

    (new TranslateInboxConversationMessageJob((string) $message->id, 'zh-CN'))
        ->handle($action, $notifier);
});

// ---------------------------------------------------------------------------
// 无默认 provider 时不翻译
// ---------------------------------------------------------------------------

it('无默认翻译 provider 时不翻译', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = null; // 不配置翻译供应商
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    $manager = createFakeTranslatorManager();
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($message->fresh()->payload)->toBeNull();
});

it('翻译 API 异常时记录日志并保留原消息', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    // 轮询池会先记录「翻译供应商失败」再由本 Action 记录「消息翻译失败」，两条 warning 都允许出现。
    Log::shouldReceive('warning')->with('翻译供应商失败，轮询下一个', Mockery::any())->atLeast()->once();
    Log::shouldReceive('warning')->with('消息翻译失败', Mockery::any())->once();

    $manager = createFakeTranslatorManager(exception: new TranslationException('API timeout'));
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($message->fresh()->payload)->toBeNull();
});

// ---------------------------------------------------------------------------
// 源语言等于目标语言时不翻译
// ---------------------------------------------------------------------------

it('源语言等于目标语言时不存储翻译', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => '你好']);

    // 检测到的源语言和目标语言相同（都是 zh-CN）
    $manager = createFakeTranslatorManager('你好', 'zh-CN');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($message->fresh()->payload)->toBeNull()
        ->and($message->fresh()->content_locale)->toBe('zh-CN');
});

// ---------------------------------------------------------------------------
// 访客语言不由翻译结果回填
// ---------------------------------------------------------------------------

it('访客消息翻译不会改写会话访客语言', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => null]);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
        'visitor_locale' => ReceptionLanguage::ChineseSimplified->value,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'नमस्ते']);

    $manager = createFakeTranslatorManager('你好', 'hi');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($conversation->fresh()->visitor_locale)->toBe(ReceptionLanguage::ChineseSimplified->value)
        ->and($contact->fresh()->locale)->toBeNull();
});

// ---------------------------------------------------------------------------
// 会话访客语言不被覆盖
// ---------------------------------------------------------------------------

it('已设置的 visitor_locale 不被翻译结果覆盖', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create(['locale' => 'hi']);
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
        'visitor_locale' => 'hi',
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create(['content' => 'Hello']);

    $manager = createFakeTranslatorManager('你好', 'en');
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($conversation->fresh()->visitor_locale)->toBe('hi');
});

// ---------------------------------------------------------------------------
// 空内容消息不翻译
// ---------------------------------------------------------------------------

it('空内容消息不翻译', function () {
    $channel = buildTranslationChannel($this->systemContext);
    $translationPlanVersionId = provisionTranslationPlanVersion($this->systemContext);
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $translationPlanVersionId,
        'contact_id' => $contact->id,
        'assigned_user_id' => $this->user->id,
    ]);
    $message = ConversationMessage::factory()->forConversation($conversation)->create([
        'role' => MessageRole::Visitor,
        'kind' => MessageKind::Image,
        'content' => null,
    ]);

    $manager = createFakeTranslatorManager();
    $action = new TranslateConversationMessageAction(new TranslationProviderPool($manager));
    $action->handle($message, $conversation, $channel);

    expect($message->fresh()->payload)->toBeNull();
});
