<?php

use App\Actions\Contact\GenerateContactAiSummaryAction;
use App\Actions\Conversation\GenerateConversationSummaryAction;
use App\Actions\Inbox\QueueInboxContactAiSummaryTranslationAction;
use App\Actions\Inbox\QueueInboxConversationSummaryTranslationsAction;
use App\Enums\AiModelType;
use App\Enums\ConversationInboxStatus;
use App\Jobs\Contact\GenerateContactAiSummaryJob;
use App\Jobs\Inbox\TranslateInboxContactAiSummaryJob;
use App\Jobs\Inbox\TranslateInboxConversationSummaryJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Models\TranslationProvider;
use App\Models\User;
use App\Services\Conversation\GoConversationSummaryBridge;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 创建 AI 摘要测试上下文。
 *
 * @return array{0: SystemContext, 1: Contact, 2: Conversation, 3: AiModel}
 */
function createConversationAiSummaryContext(): array
{
    $systemContext = SystemContext::factory()->create();
    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'summary-test-'.Str::lower((string) Str::ulid()),
        'name' => 'Summary Test Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [
            ['field' => 'key', 'type' => 'secret', 'required' => true],
        ],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $model = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'gpt-summary-test',
        'name' => 'Summary Test Model',
        'type' => AiModelType::Llm->value,
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $plan = ReceptionPlan::factory()->create();
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel((string) $model->id)
        ->create();
    $channel = Channel::factory()->create([
        'reception_plan_version_id' => $version->id,
    ]);
    $contact = Contact::factory()->visitor()->create(['locale' => 'en']);
    $conversation = Conversation::factory()->forContact($contact)->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $version->id,
        'inbox_status' => ConversationInboxStatus::AiHandling,
        'visitor_locale' => 'en',
        'summary' => null,
        'summary_locale' => null,
        'summary_translations' => null,
        'summary_last_message_seq_no' => 0,
        'ai_context' => null,
    ]);

    return [$systemContext, $contact, $conversation, $model];
}

/**
 * 给摘要测试上下文的接待方案版本写入可用翻译供应商。
 */
function enableConversationAiSummaryTranslation(SystemContext $systemContext, Conversation $conversation): TranslationProvider
{
    $provider = TranslationProvider::factory()->create();
    $version = $conversation->receptionPlanVersion()->firstOrFail();
    $snapshot = $version->snapshot_config;
    $snapshot['translation_config'] = [
        'enabled' => true,
        'failure_mode' => 'skip',
        'provider_id' => $provider->id,
    ];
    $version->update(['snapshot_config' => $snapshot]);

    return $provider;
}

test('会话摘要生成会调用 Go 运行时并写入摘要水位和摘要事实', function () {
    Bus::fake([GenerateContactAiSummaryJob::class]);
    config(['services.go_runtime.base_url' => 'http://go-runtime.test']);

    [, $contact, $conversation] = createConversationAiSummaryContext();
    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => 'I need to change the delivery address for order A100.',
    ]);
    ConversationMessage::factory()->forConversation($conversation)->aiText()->create([
        'content' => 'I can help. Please provide the new address.',
    ]);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/conversation-summary/generate')) {
            return Http::response([
                'success' => true,
                'summary' => 'Visitor wants to change the delivery address for order A100. AI asked for the new address.',
                'topics' => ['delivery address change'],
                'open_issues' => ['waiting for new address'],
                'preferences' => ['prefers English'],
            ]);
        }

        return Http::response(['success' => true]);
    });

    $notifier = Mockery::mock(ReceptionRealtimeNotifier::class);
    $notifier->shouldReceive('conversationChanged')
        ->once()
        ->with(Mockery::type(Conversation::class), 'conversation_summary_updated');

    $action = new GenerateConversationSummaryAction(
        app(GoConversationSummaryBridge::class),
        $notifier,
    );

    $result = $action->handle($conversation, force: true);
    $fresh = $conversation->fresh();

    expect($result)->toContain('Visitor wants to change')
        ->and($fresh->summary_locale)->toBe('en')
        ->and($fresh->summary_last_message_seq_no)->toBe(2)
        ->and($fresh->summary_generated_at)->not->toBeNull()
        ->and($fresh->ai_context['summary_facts']['open_issues'])->toBe(['waiting for new address']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/_helmdesk/internal/ai/conversation-summary/generate')
        && $request['locale'] === 'en'
        && $request['messages'][0]['role'] === 'visitor'
        && $request['messages'][1]['role'] === 'ai');
    Bus::assertDispatched(GenerateContactAiSummaryJob::class, fn (GenerateContactAiSummaryJob $job): bool => $job->contactId === (string) $contact->id);
});

test('联系人级 AI 摘要会从历史会话摘要生成固定字段', function () {
    config(['services.go_runtime.base_url' => 'http://go-runtime.test']);

    [, $contact, $conversation] = createConversationAiSummaryContext();
    $conversation->forceFill([
        'summary' => 'Visitor asked about delivery address changes.',
        'summary_locale' => 'en',
        'ai_context' => [
            'summary_facts' => [
                'topics' => ['delivery'],
                'open_issues' => ['confirm address'],
                'preferences' => ['English support'],
            ],
        ],
    ])->save();

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/contact-summary/generate')) {
            return Http::response([
                'success' => true,
                'profile_summary' => 'Customer often asks about order delivery changes.',
                'open_issues' => ['Confirm new delivery address'],
                'preferences' => ['English support'],
                'recent_topics' => ['Delivery update'],
            ]);
        }

        return Http::response(['success' => true]);
    });

    $notifier = Mockery::mock(ReceptionRealtimeNotifier::class);
    $notifier->shouldReceive('conversationChanged')
        ->once()
        ->with(Mockery::type(Conversation::class), 'contact_ai_summary_updated', Mockery::on(
            fn (array $meta): bool => $meta['contact_id'] === (string) $contact->id,
        ));

    $action = new GenerateContactAiSummaryAction(
        app(GoConversationSummaryBridge::class),
        $notifier,
    );

    $action->handle($contact);
    $summary = $contact->fresh()->ai_context['summary'];

    expect($summary['profile_summary'])->toBe('Customer often asks about order delivery changes.')
        ->and($summary['open_issues'])->toBe(['Confirm new delivery address'])
        ->and($summary['preferences'])->toBe(['English support'])
        ->and($summary['recent_topics'])->toBe(['Delivery update'])
        ->and($summary['source_locale'])->toBe('en')
        ->and($summary['translations'])->toBe([]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/_helmdesk/internal/ai/contact-summary/generate')
        && $request['locale'] === 'en'
        && $request['conversation_digests'][0]['open_issues'] === ['confirm address']);
});

test('收件箱会话摘要翻译队列只派发缺失当前客服语言的摘要', function () {
    Bus::fake();

    [$systemContext, $contact, $anchor] = createConversationAiSummaryContext();
    $user = User::factory()->create(['locale' => 'zh-CN']);
    enableConversationAiSummaryTranslation($systemContext, $anchor);

    $needsTranslation = Conversation::factory()->forContact($contact)->create([
        'reception_plan_version_id' => $anchor->reception_plan_version_id,
        'summary' => 'Need help with billing.',
        'summary_locale' => 'en',
        'summary_translations' => null,
    ]);
    $alreadyTranslated = Conversation::factory()->forContact($contact)->create([
        'reception_plan_version_id' => $anchor->reception_plan_version_id,
        'summary' => 'Need help with billing.',
        'summary_locale' => 'en',
        'summary_translations' => ['zh-CN' => ['text' => '需要账单帮助']],
    ]);
    $sameLocale = Conversation::factory()->forContact($contact)->create([
        'reception_plan_version_id' => $anchor->reception_plan_version_id,
        'summary' => '已经是中文',
        'summary_locale' => 'zh-CN',
    ]);

    $queued = QueueInboxConversationSummaryTranslationsAction::run(
        systemContext: $systemContext,
        user: $user,
        conversationId: (string) $anchor->id,
        conversationIds: [
            (string) $needsTranslation->id,
            (string) $alreadyTranslated->id,
            (string) $sameLocale->id,
        ],
    );

    expect($queued)->toBe(1);
    Bus::assertDispatched(TranslateInboxConversationSummaryJob::class, fn (TranslateInboxConversationSummaryJob $job): bool => $job->conversationId === (string) $needsTranslation->id
        && $job->targetLocale === 'zh-CN');
});

test('收件箱联系人 AI 摘要翻译队列只派发缺失当前语言的联系人摘要', function () {
    Bus::fake();

    [$systemContext, $contact, $anchor] = createConversationAiSummaryContext();
    enableConversationAiSummaryTranslation($systemContext, $anchor);
    $contact->update([
        'ai_context' => [
            'summary' => [
                'profile_summary' => 'Customer asks about billing.',
                'open_issues' => ['Send invoice'],
                'preferences' => [],
                'recent_topics' => ['Billing'],
                'source_locale' => 'en',
                'translations' => [],
                'updated_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $queued = QueueInboxContactAiSummaryTranslationAction::run(
        systemContext: $systemContext,
        contactId: (string) $contact->id,
        targetLocale: 'zh-CN',
    );

    expect($queued)->toBe(1);
    Bus::assertDispatched(TranslateInboxContactAiSummaryJob::class, fn (TranslateInboxContactAiSummaryJob $job): bool => $job->contactId === (string) $contact->id
        && $job->targetLocale === 'zh-CN');
});
