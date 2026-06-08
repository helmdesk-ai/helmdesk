<?php

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Actions\Conversation\GenerateConversationSubjectAction;
use App\Actions\Reception\AppendVisitorMessageAction;
use App\Actions\Reception\ResolveReceptionContextAction;
use App\Enums\AiModelPurpose;
use App\Enums\ConversationInboxStatus;
use App\Jobs\Conversation\GenerateConversationSubjectJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createConversationSubjectTestContext(?string $subject = null, ?ConversationInboxStatus $inboxStatus = null): array
{
    $systemContext = SystemContext::factory()->create();
    // 会话主题生成走全局 background_task 用途池；seed 一个全局可用 LLM 模型即可。
    $model = makeAiModel(AiModelPurpose::BackgroundTask);
    $plan = ReceptionPlan::factory()->create();
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->create();
    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);
    $contact = Contact::factory()->visitor()->create();
    $conversation = Conversation::factory()->forContact($contact)->create([
        'channel_id' => $channel->id,
        'reception_plan_version_id' => $version->id,
        'inbox_status' => $inboxStatus ?? ConversationInboxStatus::AiHandling,
        'subject' => $subject,
    ]);

    return [$systemContext, $channel, $contact, $conversation, $model];
}

test('会话主题生成会调用 Go 运行时并写入清理后的主题', function () {
    config(['services.go_runtime.base_url' => 'http://go-runtime.test']);

    [, , , $conversation] = createConversationSubjectTestContext();
    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => '我想咨询一下订单退款什么时候到账，已经等了三天。',
    ]);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/conversation-subject/generate')) {
            return Http::response([
                'success' => true,
                'subject' => ' “退款到账进度。” ',
            ]);
        }

        return Http::response(['success' => true]);
    });

    app(GenerateConversationSubjectAction::class)->handle($conversation);

    expect($conversation->fresh()->subject)->toBe('退款到账进度');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/_helmdesk/internal/ai/conversation-subject/generate')
        && $request['messages'] === ['我想咨询一下订单退款什么时候到账，已经等了三天。']);
});

test('会话主题清理不会截断中文多字节字符', function () {
    config(['services.go_runtime.base_url' => 'http://go-runtime.test']);

    [, , , $conversation] = createConversationSubjectTestContext();
    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => '你好啊',
    ]);

    Http::fake(fn (Request $request) => Http::response([
        'success' => true,
        'subject' => '问候',
    ]));

    app(GenerateConversationSubjectAction::class)->handle($conversation);

    $subject = $conversation->fresh()->subject;
    expect($subject)->toBe('问候')
        ->and(mb_check_encoding($subject, 'UTF-8'))->toBeTrue();
});

test('已有主题保持人工设置结果', function () {
    [, , , $conversation] = createConversationSubjectTestContext('人工设置主题');
    ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'content' => '帮我查一下退款。',
    ]);

    Http::fake();

    $result = app(GenerateConversationSubjectAction::class)->handle($conversation);

    expect($result)->toBe('人工设置主题')
        ->and($conversation->fresh()->subject)->toBe('人工设置主题');
    Http::assertNothingSent();
});

test('人工待接会话收到访客文本后也会派发主题生成任务', function () {
    Bus::fake();
    config(['queue.default' => 'database']);

    [, $channel, $contact, $conversation] = createConversationSubjectTestContext(null, ConversationInboxStatus::TeammatePending);

    $resolver = Mockery::mock(ResolveReceptionContextAction::class);
    $resolver->shouldReceive('handle')->once()->andReturn([
        'channel' => $channel,
        'contact' => $contact,
        'conversation' => $conversation,
        'session_token' => 'session-token',
        'created' => true,
        'signed_identity' => null,
    ]);
    $notifier = Mockery::mock(ReceptionRealtimeNotifier::class);
    $notifier->shouldReceive('conversationChanged')->once();
    $attachments = Mockery::mock(AttachUploadedAttachmentsAction::class);

    $action = new AppendVisitorMessageAction($resolver, $notifier, $attachments);
    $action->handle($channel->code, null, '我想了解企业版价格。');

    ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('content', '我想了解企业版价格。')
        ->firstOrFail();

    Bus::assertDispatched(GenerateConversationSubjectJob::class, fn (GenerateConversationSubjectJob $job): bool => $job->conversationId === (string) $conversation->id);
});

test('已有主题的访客文本消息保持当前主题状态', function () {
    Bus::fake();

    [, $channel, $contact, $conversation] = createConversationSubjectTestContext('已有主题', ConversationInboxStatus::TeammatePending);

    $resolver = Mockery::mock(ResolveReceptionContextAction::class);
    $resolver->shouldReceive('handle')->once()->andReturn([
        'channel' => $channel,
        'contact' => $contact,
        'conversation' => $conversation,
        'session_token' => 'session-token',
        'created' => false,
        'signed_identity' => null,
    ]);
    $notifier = Mockery::mock(ReceptionRealtimeNotifier::class);
    $notifier->shouldReceive('conversationChanged')->once();
    $attachments = Mockery::mock(AttachUploadedAttachmentsAction::class);

    $action = new AppendVisitorMessageAction($resolver, $notifier, $attachments);
    $action->handle($channel->code, null, '继续补充一个问题。');

    ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('content', '继续补充一个问题。')
        ->firstOrFail();

    Bus::assertNotDispatched(GenerateConversationSubjectJob::class);
});
