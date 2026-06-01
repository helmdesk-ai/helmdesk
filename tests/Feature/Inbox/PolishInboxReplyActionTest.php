<?php

use App\Enums\AiModelType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function (): void {
    $this->user = $this->createUserWithWorkspace();

    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);
});

function createReplyPolishTestModel(Workspace $workspace, array $providerAttributes = [], array $modelAttributes = []): AiModel
{
    $provider = AiProvider::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'brand' => 'custom-openai',
        'slug' => 'reply-polish-provider-'.Str::lower((string) Str::ulid()),
        'name' => 'Reply Polish Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [
            ['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true],
        ],
        'is_builtin' => false,
        'sort_order' => 0,
    ], $providerAttributes));

    return AiModel::query()->create(array_merge([
        'ai_provider_id' => $provider->id,
        'name' => 'Reply Polish Model',
        'model_id' => 'gpt-reply-polish',
        'type' => AiModelType::Llm->value,
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ], $modelAttributes));
}

function createReplyPolishConversation(Workspace $workspace, User $user, array $attributes = []): Conversation
{
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Mia',
    ]);

    return Conversation::factory()
        ->forContact($contact)
        ->assignedTo($user)
        ->create(array_merge([
            'workspace_id' => $workspace->id,
            'status' => ConversationStatus::Open,
            'inbox_status' => ConversationInboxStatus::TeammateHandling,
            'visitor_locale' => 'en-US',
            'subject' => 'Refund status',
            'summary' => 'Visitor wants to know when a refund will arrive.',
        ], $attributes));
}

test('收件箱回复助手会转发模式模型风格和会话上下文到 Go 运行时', function (): void {
    $model = createReplyPolishTestModel($this->workspace);
    $conversation = createReplyPolishConversation($this->workspace, $this->user);
    $userLocale = $this->user->locale;

    $visitorMessage = ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
        'sender_name' => 'Mia',
        'content' => 'When will my refund arrive?',
        'content_locale' => 'en-US',
    ]);
    ConversationMessage::factory()->forConversation($conversation)->create([
        'sender_user_id' => $this->user->id,
        'sender_name' => $this->user->name,
        'role' => MessageRole::Teammate,
        'kind' => MessageKind::Text,
        'content' => 'We are checking it for you.',
        'content_locale' => 'en-US',
    ]);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')) {
            return Http::response([
                'success' => true,
                'candidates' => [
                    'Hi, we are checking the refund status for you now.',
                    'Hi Mia, we are checking your refund status now.',
                    'Thanks for waiting. We are checking the refund status.',
                ],
            ]);
        }

        return Http::response(['success' => true]);
    });

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => 'hi we check refund now',
            'model_id' => $model->id,
            'mode' => 'rewrite',
            'tone' => 'friendly',
            'quoted_message_id' => $visitorMessage->id,
        ])
        ->assertOk()
        ->assertJsonCount(3, 'candidates')
        ->assertJsonPath('candidates.0.content', 'Hi, we are checking the refund status for you now.');

    Http::assertSent(function (Request $request) use ($model, $userLocale, $visitorMessage): bool {
        return str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')
            && $request['mode'] === 'rewrite'
            && $request['content'] === 'hi we check refund now'
            && $request['tone'] === 'friendly'
            && $request['model']['model_id'] === $model->model_id
            && $request['provider']['credentials']['key'] === 'test-key'
            && $request['context']['teammate_locale'] === $userLocale
            && $request['context']['visitor_locale'] === 'en-US'
            && $request['context']['conversation_subject'] === 'Refund status'
            && $request['context']['quoted_message']['content'] === $visitorMessage->content
            && count($request['context']['recent_messages']) === 2;
    });
});

test('收件箱回复助手允许空内容生成回复候选', function (): void {
    $model = createReplyPolishTestModel($this->workspace);
    $conversation = createReplyPolishConversation($this->workspace, $this->user);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')) {
            return Http::response([
                'success' => true,
                'candidates' => [
                    'We can help check that for you.',
                    'I can look into this right away.',
                    'Let me confirm the details for you.',
                ],
            ]);
        }

        return Http::response(['success' => true]);
    });

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => '',
            'model_id' => $model->id,
            'mode' => 'reply',
            'tone' => 'professional',
        ])
        ->assertOk()
        ->assertJsonCount(3, 'candidates')
        ->assertJsonPath('candidates.1.content', 'I can look into this right away.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')
        && $request['mode'] === 'reply'
        && $request['content'] === '');
});

test('收件箱回复助手在改写模式拒绝纯空白内容且不会调用运行时', function (): void {
    $model = createReplyPolishTestModel($this->workspace);
    $conversation = createReplyPolishConversation($this->workspace, $this->user);
    Http::fake();

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => '   ',
            'model_id' => $model->id,
            'mode' => 'rewrite',
            'tone' => 'keep',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');

    Http::assertNothingSent();
});

test('收件箱回复助手忽略不属于当前工作区会话的引用消息', function (): void {
    $model = createReplyPolishTestModel($this->workspace);
    $conversation = createReplyPolishConversation($this->workspace, $this->user);
    $otherWorkspace = Workspace::factory()->create();
    $otherConversation = createReplyPolishConversation($otherWorkspace, $this->user);
    $foreignMessage = ConversationMessage::factory()->forConversation($otherConversation)->visitorText()->create([
        'content' => 'This message is from another conversation.',
    ]);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')) {
            return Http::response([
                'success' => true,
                'candidates' => [
                    'We can help check that for you.',
                    'I can look into this right away.',
                    'Let me confirm the details for you.',
                ],
            ]);
        }

        return Http::response(['success' => true]);
    });

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => '',
            'model_id' => $model->id,
            'mode' => 'reply',
            'tone' => 'keep',
            'quoted_message_id' => $foreignMessage->id,
        ])
        ->assertOk()
        ->assertJsonCount(3, 'candidates');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')
        && array_key_exists('quoted_message', $request['context'])
        && $request['context']['quoted_message'] === null);
});

test('收件箱回复助手在运行时返回空候选时抛出业务错误', function (): void {
    $model = createReplyPolishTestModel($this->workspace);
    $conversation = createReplyPolishConversation($this->workspace, $this->user);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')) {
            return Http::response([
                'success' => true,
                'candidates' => [],
            ]);
        }

        return Http::response(['success' => true]);
    });

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => 'please rewrite',
            'model_id' => $model->id,
            'mode' => 'rewrite',
            'tone' => 'keep',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('conversation.errors.reply_polish_failed'));
});

test('收件箱回复助手只向运行时发送最近三十条文本消息', function (): void {
    $model = createReplyPolishTestModel($this->workspace);
    $conversation = createReplyPolishConversation($this->workspace, $this->user);

    for ($i = 1; $i <= 35; $i++) {
        ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
            'content' => "Message {$i}",
        ]);
    }

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')) {
            return Http::response([
                'success' => true,
                'candidates' => [
                    'We can help check that for you.',
                    'I can look into this right away.',
                    'Let me confirm the details for you.',
                ],
            ]);
        }

        return Http::response(['success' => true]);
    });

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => '',
            'model_id' => $model->id,
            'mode' => 'reply',
            'tone' => 'keep',
        ])
        ->assertOk();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/_helmdesk/internal/ai/reply-polish/generate')
        && count($request['context']['recent_messages']) === 30
        && $request['context']['recent_messages'][0]['content'] === 'Message 6'
        && $request['context']['recent_messages'][29]['content'] === 'Message 35');
});

test('收件箱回复润色拒绝不可用模型且不会调用运行时', function (): void {
    $conversation = createReplyPolishConversation($this->workspace, $this->user);
    Http::fake();

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => 'please polish',
            'model_id' => (string) Str::ulid(),
            'mode' => 'rewrite',
            'tone' => 'keep',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.model_id.0', __('ai.chat.selected_model_unavailable'));

    Http::assertNothingSent();
});

test('收件箱回复润色复用会话回复权限控制', function (): void {
    $model = createReplyPolishTestModel($this->workspace);
    $otherUser = User::factory()->create();
    $conversation = createReplyPolishConversation($this->workspace, $otherUser);
    Http::fake();

    $this->actingAs($this->user)
        ->postJson('/w/'.$this->workspaceSlug().'/inbox/'.$conversation->id.'/reply/polish', [
            'content' => 'please polish',
            'model_id' => $model->id,
            'mode' => 'rewrite',
            'tone' => 'keep',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('conversation.errors.reply_not_allowed_for_assignee'));

    Http::assertNothingSent();
});
