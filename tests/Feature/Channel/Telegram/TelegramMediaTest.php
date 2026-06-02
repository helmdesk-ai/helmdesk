<?php

use App\Actions\Native\Channel\Telegram\ReceiveTelegramMediaBridgeAction;
use App\Actions\Reception\ResolveTelegramReceptionContextAction;
use App\Enums\AttachmentPurpose;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentVisibility;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Enums\StorageDriver;
use App\Jobs\Telegram\SendTelegramMessageJob;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Storage\StorageProfileResolver;
use App\Services\Telegram\TelegramBotApi;
use App\Services\Telegram\TelegramHtmlConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\WithSystemContext;

require_once __DIR__.'/TelegramTestSupport.php';

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
});

/**
 * 建一个已部署接待方案的 Telegram 渠道（媒体测试用）。
 */
function makeMediaTelegramChannel(): Channel
{
    $systemContext = test()->systemContext;
    $version = createTelegramDeployablePlanVersion($systemContext);

    return Channel::factory()->telegram()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);
}

test('Telegram 入站图片下载并创建 Image 消息与附件', function () {
    Storage::fake('local');
    Http::fake([
        '*/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'F1', 'file_path' => 'photos/file_1.jpg']]),
        '*/file/bot*' => Http::response('FAKE-IMAGE-BYTES'),
        // 会话创建可能触发欢迎语出站，统一兜底。
        '*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $channel = makeMediaTelegramChannel();

    $result = ReceiveTelegramMediaBridgeAction::run(
        $channel->code,
        $channel->settings->webhook_secret,
        77001,
        77001,
        '访客',
        null,
        null,
        9001,
        'image',
        'F1',
        null,
        null,
        null,
    );

    // 无 caption → 不返回可唤起 AI 的文本消息。
    expect($result['visitor_message_id'])->toBeNull();

    $conversation = Conversation::query()->findOrFail($result['conversation_id']);
    $imageMessage = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('kind', MessageKind::Image)
        ->first();

    expect($imageMessage)->not->toBeNull()
        ->and($imageMessage->client_msg_id)->toBe('tg_9001')
        ->and($imageMessage->payload['attachments'][0]['id'] ?? null)->not->toBeNull();

    $attachment = Attachment::query()
        ->where('attachable_type', $imageMessage->getMorphClass())
        ->where('attachable_id', $imageMessage->getKey())
        ->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->purpose)->toBe(AttachmentPurpose::ConversationImage)
        ->and($attachment->byte_size)->toBe(strlen('FAKE-IMAGE-BYTES'))
        ->and(Storage::disk('local')->get($attachment->object_key))->toBe('FAKE-IMAGE-BYTES');
});

test('Telegram 入站文档带 caption 时额外落文本消息', function () {
    Storage::fake('local');
    Http::fake([
        '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_2.pdf']]),
        '*/file/bot*' => Http::response('PDF-BYTES'),
        '*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $channel = makeMediaTelegramChannel();

    $result = ReceiveTelegramMediaBridgeAction::run(
        $channel->code,
        $channel->settings->webhook_secret,
        77002,
        77002,
        '访客',
        null,
        null,
        9002,
        'file',
        'F2',
        'report.pdf',
        'application/pdf',
        '麻烦看下这个报告',
    );

    $conversation = Conversation::query()->findOrFail($result['conversation_id']);

    $fileMessage = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('kind', MessageKind::File)
        ->first();
    expect($fileMessage)->not->toBeNull();

    // caption 作为独立文本消息落库，并作为可唤起 AI 的返回值。
    $captionMessage = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('kind', MessageKind::Text)
        ->where('role', MessageRole::Visitor)
        ->first();
    expect($captionMessage)->not->toBeNull()
        ->and($captionMessage->content)->toBe('麻烦看下这个报告')
        ->and($result['visitor_message_id'])->toBe((string) $captionMessage->id);

    $attachment = Attachment::query()
        ->where('attachable_id', $fileMessage->getKey())
        ->first();
    expect($attachment->purpose)->toBe(AttachmentPurpose::ConversationFile)
        ->and($attachment->original_name)->toBe('report.pdf');
});

test('Telegram 入站媒体对同一 message_id 幂等', function () {
    Storage::fake('local');
    Http::fake([
        '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_3.jpg']]),
        '*/file/bot*' => Http::response('BYTES'),
        '*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $channel = makeMediaTelegramChannel();
    $args = [$channel->code, $channel->settings->webhook_secret, 77003, 77003, '访客', null, null, 9003, 'image', 'F3', null, null, null];

    ReceiveTelegramMediaBridgeAction::run(...$args);
    ReceiveTelegramMediaBridgeAction::run(...$args);

    expect(ConversationMessage::query()->where('client_msg_id', 'tg_9003')->count())->toBe(1)
        ->and(Attachment::query()->count())->toBe(1);
});

test('Telegram 出站图片消息调用 sendPhoto 上传附件', function () {
    Storage::fake('local');
    Http::fake([
        '*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 9300]]),
        '*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
    // 防止创建钩子的发送任务在附件就绪前同步执行。
    Queue::fake();

    $channel = makeMediaTelegramChannel();
    $context = app(ResolveTelegramReceptionContextAction::class)->handle($channel->code, '88001', '访客');
    $conversation = $context['conversation'];

    $imageMessage = ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Teammate,
        'sender_name' => '客服',
        'kind' => MessageKind::Image,
        'content' => null,
    ]);

    // 落一张图片附件并绑定到该消息。
    $profile = app(StorageProfileResolver::class)->localProfile();
    $objectKey = 'conversations/'.Str::ulid().'.jpg';
    Storage::disk('local')->put($objectKey, 'OUTBOUND-IMG');
    Attachment::query()->create([
        'storage_profile_id' => $profile->id,
        'disk' => StorageDriver::Local,
        'object_key' => $objectKey,
        'original_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'byte_size' => strlen('OUTBOUND-IMG'),
        'visibility' => AttachmentVisibility::Private,
        'purpose' => AttachmentPurpose::ConversationImage,
        'status' => AttachmentStatus::Attached,
        'attachable_type' => $imageMessage->getMorphClass(),
        'attachable_id' => $imageMessage->getKey(),
        'metadata' => [],
        'uploaded_at' => now(),
        'attached_at' => now(),
    ]);

    (new SendTelegramMessageJob((string) $imageMessage->id))->handle(app(TelegramBotApi::class), app(TelegramHtmlConverter::class));

    expect($imageMessage->fresh()->delivery_status)->toBe(MessageDeliveryStatus::Sent)
        ->and($imageMessage->fresh()->payload['telegram']['message_id'] ?? null)->toBe(9300);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto'));
});
