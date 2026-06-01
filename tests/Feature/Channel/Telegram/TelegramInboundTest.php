<?php

use App\Actions\Native\Channel\Telegram\ReceiveTelegramUpdateBridgeAction;
use App\Enums\ContactSource;
use App\Enums\ConversationEntryMode;
use App\Enums\IdentityType;
use App\Enums\MessageRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\WithWorkspace;

require_once __DIR__.'/TelegramTestSupport.php';

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
});

/**
 * 默认模拟 Telegram 用户没有头像，避免非头像测试发起真实 Bot API 请求。
 */
function fakeTelegramProfilePhotosUnavailable(): void
{
    Http::fake([
        '*/getUserProfilePhotos' => Http::response(['ok' => true, 'result' => ['photos' => []]]),
    ]);
}

/**
 * 创建一个带已知 webhook_secret、已部署接待方案的 Telegram 渠道。
 */
function makeInboundTelegramChannel(): Channel
{
    $workspace = test()->workspace;
    $version = createTelegramDeployablePlanVersion($workspace);

    return Channel::factory()->telegram()->create([
        'workspace_id' => $workspace->id,
        'reception_plan_id' => $version->reception_plan_id,
    ]);
}

test('Telegram 入站消息创建会话与访客消息', function () {
    fakeTelegramProfilePhotosUnavailable();

    $channel = makeInboundTelegramChannel();
    $secret = $channel->settings->webhook_secret;

    $result = ReceiveTelegramUpdateBridgeAction::run(
        $channel->code,
        $secret,
        99001,
        99001,
        '小明',
        null,
        'xiaoming',
        '你好，我要咨询订单',
        5001,
    );

    expect($result['conversation_id'])->not->toBe('')
        ->and($result['inbox_status'])->not->toBe('')
        ->and($result['visitor_message_id'])->not->toBeNull();

    $conversation = Conversation::query()->findOrFail($result['conversation_id']);
    expect($conversation->channel_id)->toBe($channel->id)
        ->and($conversation->entry_mode)->toBe(ConversationEntryMode::Telegram);

    $message = ConversationMessage::query()->findOrFail($result['visitor_message_id']);
    expect($message->role)->toBe(MessageRole::Visitor)
        ->and($message->content)->toBe('你好，我要咨询订单')
        ->and($message->payload['telegram']['message_id'] ?? null)->toBe(5001)
        ->and($message->client_msg_id)->toBe('tg_5001');

    // 访客身份以 ExternalId + telegram:{code} namespace 落库。
    $identity = ContactIdentity::query()
        ->where('workspace_id', $channel->workspace_id)
        ->where('type', IdentityType::ExternalId)
        ->where('namespace', 'telegram:'.$channel->code)
        ->where('value', '99001')
        ->first();
    expect($identity)->not->toBeNull()
        ->and($identity->contact->source)->toBe(ContactSource::Telegram);
});

test('Telegram 入站消息会同步用户头像到联系人', function () {
    Storage::fake('local');
    Http::fake([
        '*/getUserProfilePhotos' => Http::response([
            'ok' => true,
            'result' => [
                'photos' => [[
                    ['file_id' => 'small-avatar'],
                    ['file_id' => 'large-avatar'],
                ]],
            ],
        ]),
        '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/avatar.jpg']]),
        '*/file/bot*' => Http::response('AVATAR-BYTES'),
    ]);

    $channel = makeInboundTelegramChannel();
    $secret = $channel->settings->webhook_secret;

    ReceiveTelegramUpdateBridgeAction::run(
        $channel->code,
        $secret,
        99021,
        99021,
        '头像用户',
        null,
        'avatar_user',
        '你好',
        5101,
    );

    $identity = ContactIdentity::query()
        ->where('workspace_id', $channel->workspace_id)
        ->where('namespace', 'telegram:'.$channel->code)
        ->where('value', '99021')
        ->firstOrFail();
    $contact = $identity->contact->fresh();
    $attachment = Attachment::query()
        ->where('attachable_type', $contact->getMorphClass())
        ->where('attachable_id', $contact->getKey())
        ->firstOrFail();

    expect($contact->avatar_url)->toStartWith('/attachments/dl?')
        ->and($contact->avatar_synced_at)->not->toBeNull()
        ->and($attachment->purpose->value)->toBe('avatar')
        ->and(Storage::disk('local')->get($attachment->object_key))->toBe('AVATAR-BYTES');
});

test('Telegram 无头像访客只探测一次头像，后续消息不再请求 Bot API', function () {
    Http::fake([
        '*/getUserProfilePhotos' => Http::response(['ok' => true, 'result' => ['photos' => []]]),
    ]);

    $channel = makeInboundTelegramChannel();
    $secret = $channel->settings->webhook_secret;

    foreach ([5201, 5202] as $messageId) {
        ReceiveTelegramUpdateBridgeAction::run(
            $channel->code,
            $secret,
            99022,
            99022,
            '无头像用户',
            null,
            'no_avatar_user',
            '你好',
            $messageId,
        );
    }

    // 第一条消息探测一次后即打标 avatar_synced_at，第二条消息不应再请求 getUserProfilePhotos。
    $probeCount = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/getUserProfilePhotos'))
        ->count();

    $contact = ContactIdentity::query()
        ->where('workspace_id', $channel->workspace_id)
        ->where('namespace', 'telegram:'.$channel->code)
        ->where('value', '99022')
        ->firstOrFail()
        ->contact->fresh();

    expect($probeCount)->toBe(1)
        ->and($contact->avatar_synced_at)->not->toBeNull()
        ->and($contact->avatar_url)->toBe(Contact::DEFAULT_AVATAR_URL);
});

test('Telegram /start 命令只创建会话，不落库访客消息也不唤起 AI', function () {
    // 会话创建可能触发欢迎语出站，拦截避免真实网络请求。
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $channel = makeInboundTelegramChannel();
    $secret = $channel->settings->webhook_secret;

    $result = ReceiveTelegramUpdateBridgeAction::run(
        $channel->code,
        $secret,
        99010,
        99010,
        '新用户',
        null,
        'newcomer',
        '/start',
        8001,
    );

    // 会话已创建，但不返回访客消息——Go 侧据此跳过 AI 唤起。
    expect($result['conversation_id'])->not->toBe('')
        ->and($result['visitor_message_id'])->toBeNull();

    $conversation = Conversation::query()->findOrFail($result['conversation_id']);

    // /start 不应作为访客消息落库。
    expect(ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Visitor)
        ->count())->toBe(0)
        ->and(ConversationMessage::query()->where('client_msg_id', 'tg_8001')->exists())->toBeFalse();
});

test('Telegram /start 携带 deep-link payload 也按命令处理', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $channel = makeInboundTelegramChannel();
    $secret = $channel->settings->webhook_secret;

    $result = ReceiveTelegramUpdateBridgeAction::run(
        $channel->code,
        $secret,
        99011,
        99011,
        '推广来源用户',
        null,
        null,
        '/start ref_campaign_42',
        8002,
    );

    expect($result['visitor_message_id'])->toBeNull()
        ->and(ConversationMessage::query()->where('client_msg_id', 'tg_8002')->exists())->toBeFalse();
});

test('Telegram 入站对同一 message_id 幂等', function () {
    fakeTelegramProfilePhotosUnavailable();

    $channel = makeInboundTelegramChannel();
    $secret = $channel->settings->webhook_secret;

    $args = [$channel->code, $secret, 99002, 99002, '阿强', null, null, '重复消息', 6001];
    ReceiveTelegramUpdateBridgeAction::run(...$args);
    ReceiveTelegramUpdateBridgeAction::run(...$args);

    expect(ConversationMessage::query()->where('client_msg_id', 'tg_6001')->count())->toBe(1);
});

test('Telegram 入站 secret 不符时拒绝', function () {
    $channel = makeInboundTelegramChannel();

    ReceiveTelegramUpdateBridgeAction::run(
        $channel->code,
        'wrong-secret',
        99003,
        99003,
        '某人',
        null,
        null,
        '你好',
        7001,
    );
})->throws(AccessDeniedHttpException::class);
