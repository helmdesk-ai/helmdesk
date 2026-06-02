<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function createWorkspaceAiProvider(Workspace $workspace, array $attributes = []): AiProvider
{
    return AiProvider::query()->create(array_merge([
        'brand' => 'custom-openai',
        'slug' => 'workspace-ai-provider',
        'name' => 'Workspace Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test-key'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ], $attributes));
}

function createWorkspaceAiModel(AiProvider $provider, array $attributes = []): AiModel
{
    return AiModel::query()->create(array_merge([
        'ai_provider_id' => $provider->id,
        'name' => 'Workspace Model',
        'model_id' => 'gpt-4o',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ], $attributes));
}

test('访客用户会被重定向到登录页面', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('inbox'))->assertRedirect(route('login'));
});

test('已认证管理员进入仪表盘和可以打开收件箱', function () {
    [$workspace, $user] = createWorkspaceWithOwner();
    $provider = createWorkspaceAiProvider($workspace);
    $model = createWorkspaceAiModel($provider);

    $this->actingAs($user, 'admin');

    $this->get(route('dashboard'))
        ->assertRedirect(route('workspace.dashboard'));

    $this->get(route('workspace.home'))
        ->assertRedirect(route('workspace.dashboard'));

    $this->get(route('workspace.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('workspaceUserContext')
            ->has('aiAssistantLlmModelOptions', 1)
            ->where('aiAssistantLlmModelOptions.0.value', (string) $model->id)
            ->where('workspaceUserContext.workspace_slug', 'admin')
            ->missing('currentWorkspace')
        );

    $this->get(route('inbox'))
        ->assertRedirect(route('workspace.inbox.show'));

    $this->get(route('workspace.inbox.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->has('enabled_web_channels', 0)
            ->has('reply_assistant_mode_options', 2)
            ->has('reply_polish_tone_options', 4)
            ->has('workspaceUserContext')
            ->has('aiAssistantLlmModelOptions', 1)
            ->where('aiAssistantLlmModelOptions.0.value', (string) $model->id)
            ->where('workspaceUserContext.workspace_slug', 'admin')
            ->missing('currentWorkspace')
        );
});

test('访问仪表盘刷新用户最后活跃时间戳', function () {
    [$workspace, $user] = createWorkspaceWithOwner();
    $previousLastActiveAt = now()->subDay();
    $user->forceFill(['last_active_at' => $previousLastActiveAt])->save();

    $this->actingAs($user, 'admin')
        ->get(route('workspace.dashboard'))
        ->assertOk();

    $updatedLastActiveAt = $user->fresh()->last_active_at;

    expect($updatedLastActiveAt)->not->toBeNull()
        ->and($updatedLastActiveAt->isAfter($previousLastActiveAt))->toBeTrue();
});

test('管理员在收件箱接收 AI 助手模型选项', function () {
    [$workspace] = createWorkspaceWithOwner();
    $provider = createWorkspaceAiProvider($workspace);
    $model = createWorkspaceAiModel($provider);

    $admin = createSuperAdmin();

    $this->actingAs($admin, 'admin')
        ->get(route('workspace.inbox.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->where('canManageAi', true)
            ->has('aiAssistantLlmModelOptions', 1)
            ->where('aiAssistantLlmModelOptions.0.value', (string) $model->id)
        );
});

test('收件箱只显示非已删除网页频道', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $enabledChannel = Channel::factory()->create([
        'name' => '官网主站',
    ]);

    $secondChannel = Channel::factory()->create([
        'name' => '帮助中心',
    ]);

    Channel::factory()->create([
        'name' => '其他工作区网站',
    ]);

    $deletedChannel = Channel::factory()->create([
        'name' => '已删除网站',
    ]);
    $deletedChannel->delete();

    $this->actingAs($user, 'admin')
        ->get(route('workspace.inbox.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->has('enabled_web_channels', 3)
            ->where('enabled_web_channels.0.type_label', '网站')
            ->etc()
        );
});
