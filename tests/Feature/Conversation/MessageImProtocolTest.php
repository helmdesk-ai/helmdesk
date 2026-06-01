<?php

use App\Actions\Contact\ShowContactConversationTimelineAction;
use App\Actions\Reception\AppendAiMessageAction;
use App\Actions\Reception\AppendTeammateMessageAction;
use App\Actions\Reception\AppendVisitorMessageAction;
use App\Actions\Reception\RecallTeammateMessageAction;
use App\Actions\Reception\RecallVisitorMessageAction;
use App\Actions\Reception\StartOrResumeReceptionSessionAction;
use App\Enums\ConversationEntryMode;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Reception\ReceptionStateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * 创建一条用于 IM 协议测试的接待方案版本 + channel，避免与 ReceptionTest 全局 helper 冲突。
 */
function imProtocolCreateChannel(): Channel
{
    $workspace = Workspace::factory()->create();
    $provider = AiProvider::query()->create([
        'workspace_id' => $workspace->id,
        'brand' => 'custom-openai',
        'slug' => 'im-provider-'.Str::lower(Str::random(6)),
        'name' => 'IM Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $model = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'IM Model',
        'model_id' => 'gpt-im',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $plan = ReceptionPlan::factory()->for($workspace)->create([
        'name' => 'IM Plan-'.Str::lower(Str::random(6)),
    ]);
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withoutAutoMessages()
        ->withReceptionModel($model->id)
        ->create();

    return Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
    ]);
}

/**
 * 启动一个新的接待会话并返回 channel + session_token，便于后续测试调用。
 *
 * @return array{0: Channel, 1: string}
 */
function imProtocolStartSession(?Channel $channel = null): array
{
    $channel ??= imProtocolCreateChannel();
    $state = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
    );

    return [$channel, $state->session_token];
}

test('seq_no 在会话内按发送顺序单调递增', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'first');
    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'reply one');
    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'second');
    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'reply two');

    $seqNos = ConversationMessage::query()
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('seq_no')
        ->all();

    expect($seqNos)->toBe([1, 2, 3, 4]);

    $conversation = Conversation::query()->firstOrFail();
    expect($conversation->next_seq_no)->toBe(4);
});

test('seq_no 计数器在每条新会话上独立', function () {
    $channel = imProtocolCreateChannel();

    [, $tokenA] = imProtocolStartSession($channel);
    app(AppendVisitorMessageAction::class)->handle($channel->code, $tokenA, 'first conversation');
    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'reply');

    // 关掉第一条会话后再开一条，使其能在同 contact 下再次创建。
    $first = Conversation::query()->firstOrFail();
    $first->forceFill([
        'status' => ConversationStatus::Closed,
        'closed_at' => now(),
    ])->save();

    [, $tokenB] = imProtocolStartSession($channel);
    app(AppendVisitorMessageAction::class)->handle($channel->code, $tokenB, 'second conversation');

    $second = Conversation::query()->orderByDesc('created_at')->orderByDesc('id')->first();
    expect($second->id)->not->toBe($first->id)
        ->and($second->next_seq_no)->toBe(1)
        ->and(ConversationMessage::query()->where('conversation_id', $second->id)->value('seq_no'))->toBe(1);
});

test('相同 client_msg_id 不会重复落库', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    $clientMsgId = 'msg-'.Str::ulid();

    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $sessionToken,
        'first send',
        clientMsgId: $clientMsgId,
    );
    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $sessionToken,
        'first send',
        clientMsgId: $clientMsgId,
    );

    expect(ConversationMessage::query()->where('client_msg_id', $clientMsgId)->count())->toBe(1)
        ->and(ConversationMessage::query()->count())->toBe(1);
});

test('未带 client_msg_id 的访客消息允许同内容多次发送', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'duplicate');
    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'duplicate');

    expect(ConversationMessage::query()->count())->toBe(2);
});

test('客服回复也支持 client_msg_id 幂等', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();
    $conversation->refresh();

    $clientMsgId = 'agent-'.Str::ulid();

    $first = app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation,
        actor: $teammate,
        content: 'hello visitor',
        clientMsgId: $clientMsgId,
    );

    $second = app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        content: 'hello visitor',
        clientMsgId: $clientMsgId,
    );

    expect((string) $first->id)->toBe((string) $second->id)
        ->and(ConversationMessage::query()->where('client_msg_id', $clientMsgId)->count())->toBe(1);
});

test('访客发消息后 agent 未读数清零、自身未读数累加', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'opening line');

    $conversation = Conversation::query()->firstOrFail();
    expect($conversation->unread_agent_message_count)->toBe(1)
        ->and($conversation->unread_visitor_message_count)->toBe(0);

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'question');

    $conversation->refresh();
    expect($conversation->unread_visitor_message_count)->toBe(1)
        ->and($conversation->unread_agent_message_count)->toBe(0);
});

test('AI 与客服分别累加 agent 未读数', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'first');
    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'second');

    expect(Conversation::query()->firstOrFail()->unread_agent_message_count)->toBe(2);
});

test('quoted_message_id 跨会话引用会被静默丢弃', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'original');
    $localMessage = ConversationMessage::query()->firstOrFail();

    // 另一条会话用来产生不属于本会话的 message id。
    [$otherChannel, $otherToken] = imProtocolStartSession();
    app(AppendVisitorMessageAction::class)->handle($otherChannel->code, $otherToken, 'foreign');
    $foreignMessage = ConversationMessage::query()
        ->where('conversation_id', '!=', $localMessage->conversation_id)
        ->firstOrFail();

    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $sessionToken,
        'reply with foreign quote',
        quotedMessageId: (string) $foreignMessage->id,
    );

    $latest = ConversationMessage::query()
        ->where('conversation_id', $localMessage->conversation_id)
        ->where('content', 'reply with foreign quote')
        ->firstOrFail();

    expect($latest->quoted_message_id)->toBeNull();
});

test('quoted_message_id 同会话内引用会被保留', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'first');
    $target = ConversationMessage::query()->firstOrFail();

    app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $sessionToken,
        'follow-up',
        quotedMessageId: (string) $target->id,
    );

    $followUp = ConversationMessage::query()
        ->where('content', 'follow-up')
        ->firstOrFail();

    expect($followUp->quoted_message_id)->toBe((string) $target->id);
});

test('访客状态会下发引用消息快照', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'answer from ai');
    $target = ConversationMessage::query()->where('role', MessageRole::Ai)->firstOrFail();

    $state = app(AppendVisitorMessageAction::class)->handle(
        $channel->code,
        $sessionToken,
        'follow-up question',
        quotedMessageId: (string) $target->id,
    );

    $followUp = collect($state->messages)->firstWhere('content', 'follow-up question');

    expect($followUp->quoted_message)->not->toBeNull()
        ->and($followUp->quoted_message->id)->toBe((string) $target->id)
        ->and($followUp->quoted_message->preview)->toBe('answer from ai')
        ->and($followUp->quoted_message->content)->toBe('answer from ai')
        ->and($followUp->quoted_message->attachments)->toBe([]);
});

test('客服时间线会下发引用消息快照', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create(['name' => 'Reply Agent']);
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'visitor original');
    $target = ConversationMessage::query()->where('role', MessageRole::Visitor)->firstOrFail();

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        content: 'quoted reply',
        quotedMessageId: (string) $target->id,
    );

    $timeline = app(ShowContactConversationTimelineAction::class)
        ->handle($conversation->contact, viewer: $teammate);
    $reply = collect($timeline->entries)->firstWhere('content', 'quoted reply');

    expect($reply->quoted_message)->not->toBeNull()
        ->and($reply->quoted_message->id)->toBe((string) $target->id)
        ->and($reply->quoted_message->preview)->toBe('visitor original')
        ->and($reply->quoted_message->content)->toBe('visitor original')
        ->and($reply->quoted_message->attachments)->toBe([]);
});

test('访客撤回自己消息后设置 recalled_at 并保留原内容供审计', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'oops');

    $message = ConversationMessage::query()->firstOrFail();

    app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) $message->id,
    );

    $message->refresh();

    expect($message->recalled_at)->not->toBeNull()
        ->and($message->content)->toBe('oops');
});

test('访客无法撤回 AI 发出的消息', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'hello');

    $aiMessage = ConversationMessage::query()->where('role', MessageRole::Ai)->firstOrFail();

    expect(fn () => app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) $aiMessage->id,
    ))->toThrow(BusinessException::class);
});

test('已撤回的消息不能重复撤回', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'x');
    $message = ConversationMessage::query()->firstOrFail();

    app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) $message->id,
    );

    expect(fn () => app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) $message->id,
    ))->toThrow(BusinessException::class);
});

test('撤回不存在的消息抛 404', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    expect(fn () => app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) Str::ulid(),
    ))->toThrow(NotFoundHttpException::class);
});

test('客服可以撤回自己发出的消息但不能撤回访客消息', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'visitor msg');
    $visitorMessage = ConversationMessage::query()->where('role', MessageRole::Visitor)->firstOrFail();

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();
    $conversation->refresh();

    $reply = app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation,
        actor: $teammate,
        content: 'agent reply',
    );

    app(RecallTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        messageId: (string) $reply->id,
    );

    expect($reply->refresh()->recalled_at)->not->toBeNull();

    expect(fn () => app(RecallTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        messageId: (string) $visitorMessage->id,
    ))->toThrow(BusinessException::class);
});

test('客服可以撤回当前接管会话内的 AI 消息', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);

    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'ai answer');
    $aiMessage = ConversationMessage::query()->where('role', MessageRole::Ai)->firstOrFail();

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    app(RecallTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        messageId: (string) $aiMessage->id,
    );

    expect($aiMessage->refresh()->recalled_at)->not->toBeNull();
});

test('新消息默认 delivery_status 为 sent', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'hello');

    $message = ConversationMessage::query()->firstOrFail();

    expect($message->delivery_status)->toBe(MessageDeliveryStatus::Sent);
});

test('访客端只看到自己撤回消息的原文用于重新编辑', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'visitor own');
    $visitorMessage = ConversationMessage::query()->where('role', MessageRole::Visitor)->firstOrFail();

    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'ai answer');
    $aiMessage = ConversationMessage::query()->where('role', MessageRole::Ai)->firstOrFail();

    // 访客撤回自己消息
    app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) $visitorMessage->id,
    );

    // 客服接管并撤回 AI 消息
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);
    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();
    app(RecallTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        messageId: (string) $aiMessage->id,
    );

    $state = app(ReceptionStateBuilder::class)::build(
        $channel,
        $conversation->fresh(),
        $sessionToken,
    );

    $visitorState = collect($state->messages)->firstWhere('id', (string) $visitorMessage->id);
    $aiState = collect($state->messages)->firstWhere('id', (string) $aiMessage->id);

    expect($visitorState->recalled_content)->toBe('visitor own')
        ->and($visitorState->content)->toBe('')
        ->and($aiState->recalled_content)->toBeNull()
        ->and($aiState->content)->toBe('');
});

test('客服端时间线只对操作者本人下发 recalled_content', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammateA = User::factory()->create();
    $teammateB = User::factory()->create();
    $workspace->users()->attach($teammateA->id, ['role' => 'admin']);
    $workspace->users()->attach($teammateB->id, ['role' => 'admin']);

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'visitor secret');
    $visitorMessage = ConversationMessage::query()->where('role', MessageRole::Visitor)->firstOrFail();
    app(AppendAiMessageAction::class)->handle(Conversation::query()->firstOrFail(), 'ai answer');
    $aiMessage = ConversationMessage::query()->where('role', MessageRole::Ai)->firstOrFail();

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill([
        'assigned_user_id' => $teammateA->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    // teammateA 发一条回复并立刻撤回
    $teammateMessage = app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammateA,
        content: 'agent reply by A',
    );
    app(RecallTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammateA,
        messageId: (string) $teammateMessage->id,
    );
    // 访客撤回自己消息
    app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) $visitorMessage->id,
    );
    // teammateA 撤回 AI 消息
    app(RecallTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammateA,
        messageId: (string) $aiMessage->id,
    );

    $contact = $conversation->contact;
    $timelineA = app(ShowContactConversationTimelineAction::class)
        ->handle($contact, viewer: $teammateA);
    $timelineB = app(ShowContactConversationTimelineAction::class)
        ->handle($contact, viewer: $teammateB);

    $teammateEntryA = collect($timelineA->entries)->firstWhere('id', (string) $teammateMessage->id);
    $teammateEntryB = collect($timelineB->entries)->firstWhere('id', (string) $teammateMessage->id);
    $aiEntryA = collect($timelineA->entries)->firstWhere('id', (string) $aiMessage->id);
    $aiEntryB = collect($timelineB->entries)->firstWhere('id', (string) $aiMessage->id);
    $visitorEntryA = collect($timelineA->entries)->firstWhere('id', (string) $visitorMessage->id);

    // teammateA 看自己的撤回消息：有原文
    expect($teammateEntryA->recalled_content)->toBe('agent reply by A')
        // teammateB 看 teammateA 的撤回消息：没原文
        ->and($teammateEntryB->recalled_content)->toBeNull()
        // 任意客服看 AI 撤回消息：都有原文（工作区内部）
        ->and($aiEntryA->recalled_content)->toBe('ai answer')
        ->and($aiEntryB->recalled_content)->toBe('ai answer')
        // 客服永远看不到访客撤回原文
        ->and($visitorEntryA->recalled_content)->toBeNull();
});

test('未传 viewer 时时间线不下发任何 recalled_content', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'x');
    $visitorMessage = ConversationMessage::query()->firstOrFail();
    app(RecallVisitorMessageAction::class)->handle(
        channelCode: $channel->code,
        sessionToken: $sessionToken,
        messageId: (string) $visitorMessage->id,
    );

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $timeline = app(ShowContactConversationTimelineAction::class)
        ->handle($conversation->contact);

    $entry = collect($timeline->entries)->firstWhere('id', (string) $visitorMessage->id);

    expect($entry->recalled_at)->not->toBeNull()
        ->and($entry->recalled_content)->toBeNull();
});

test('对工具消息禁止撤回', function () {
    [$channel, $sessionToken] = imProtocolStartSession();
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();

    $toolMessage = ConversationMessage::factory()
        ->forConversation($conversation)
        ->toolCall()
        ->create();

    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    expect(fn () => app(RecallTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        messageId: (string) $toolMessage->id,
    ))->toThrow(BusinessException::class);

    expect($toolMessage->kind)->toBe(MessageKind::ToolCall);
});

test('B端引用访客消息时直接使用落库发送者名称', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create(['name' => 'Agent One']);
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'visitor original');
    $target = ConversationMessage::query()->where('role', MessageRole::Visitor)->firstOrFail();

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    app(AppendTeammateMessageAction::class)->handle(
        conversation: $conversation->fresh(),
        actor: $teammate,
        content: 'reply quoting visitor',
        quotedMessageId: (string) $target->id,
    );

    $timeline = app(ShowContactConversationTimelineAction::class)
        ->handle($conversation->contact, viewer: $teammate);
    $reply = collect($timeline->entries)->firstWhere('content', 'reply quoting visitor');

    expect($target->sender_name)->toBe('')
        ->and($reply->quoted_message->sender_name)->toBe('');
});

test('C端引用匿名访客消息时不生成已翻译发送者名称', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'visitor said something');
    $target = ConversationMessage::query()->where('role', MessageRole::Visitor)->firstOrFail();

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();

    app(AppendAiMessageAction::class)->handle($conversation, 'AI replied', quotedMessageId: (string) $target->id);

    $state = ReceptionStateBuilder::build($channel, $conversation->fresh(), $sessionToken);
    $aiMessage = collect($state->messages)->firstWhere('content', 'AI replied');

    expect($target->sender_name)->toBe('')
        ->and($aiMessage->quoted_message->sender_name)->toBe('');
});

test('B端引用 AI 消息时 sender_name 由后端填充，前端无需 fallback', function () {
    [$channel, $sessionToken] = imProtocolStartSession();

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->firstOrFail();
    app(AppendAiMessageAction::class)->handle($conversation, 'AI said something');
    $target = ConversationMessage::query()->where('role', MessageRole::Ai)->firstOrFail();

    $workspace = Workspace::query()->findOrFail($channel->workspace_id);
    $teammate = User::factory()->create(['name' => 'Agent Two']);
    $workspace->users()->attach($teammate->id, ['role' => 'admin']);
    $conversation->forceFill([
        'assigned_user_id' => $teammate->id,
        'inbox_status' => ConversationInboxStatus::TeammateHandling,
    ])->save();

    app(AppendVisitorMessageAction::class)->handle($channel->code, $sessionToken, 'visitor quotes AI', quotedMessageId: (string) $target->id);

    $timeline = app(ShowContactConversationTimelineAction::class)
        ->handle($conversation->contact, viewer: $teammate);
    $visitorMsg = collect($timeline->entries)->firstWhere('content', 'visitor quotes AI');

    expect($visitorMsg->quoted_message->sender_name)->not->toBeNull()
        ->and($visitorMsg->quoted_message->sender_name)->toBe($target->sender_name);
});
