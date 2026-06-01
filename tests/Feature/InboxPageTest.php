<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function createWorkspaceAiProvider(Workspace $workspace, array $attributes = []): AiProvider
{
    return AiProvider::query()->create(array_merge([
        'workspace_id' => $workspace->id,
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

test('已认证用户进入工作区仪表盘和可以打开收件箱', function () {
    [$workspace, $user] = createWorkspaceWithOwner();
    $provider = createWorkspaceAiProvider($workspace);
    $model = createWorkspaceAiModel($provider);

    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertRedirect(route('workspace.dashboard', ['slug' => $workspace->slug]));

    $this->get(route('workspace.home', ['slug' => $workspace->slug]))
        ->assertRedirect(route('workspace.dashboard', ['slug' => $workspace->slug]));

    $this->get(route('workspace.dashboard', ['slug' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('workspaces', 1)
            ->has('workspaceUserContext')
            ->has('aiAssistantLlmModelOptions', 1)
            ->where('aiAssistantLlmModelOptions.0.value', (string) $model->id)
            ->where('workspaceUserContext.workspace_slug', $workspace->slug)
            ->missing('currentWorkspace')
        );

    $this->get(route('inbox'))
        ->assertRedirect(route('workspace.inbox.show', ['slug' => $workspace->slug]));

    $this->get(route('workspace.inbox.show', ['slug' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->has('enabled_web_channels', 0)
            ->has('reply_assistant_mode_options', 2)
            ->has('reply_polish_tone_options', 4)
            ->has('workspaces', 1)
            ->has('workspaceUserContext')
            ->has('aiAssistantLlmModelOptions', 1)
            ->where('aiAssistantLlmModelOptions.0.value', (string) $model->id)
            ->where('workspaceUserContext.workspace_slug', $workspace->slug)
            ->missing('currentWorkspace')
        );
});

test('访问工作区仪表盘刷新成员最后活跃时间戳', function () {
    [$workspace, $user] = createWorkspaceWithOwner();
    $previousLastActiveAt = now()->subDay();

    $user->workspaces()->updateExistingPivot($workspace->id, [
        'last_active_at' => $previousLastActiveAt,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['slug' => $workspace->slug]))
        ->assertOk();

    $updatedLastActiveAt = DB::table('user_workspace')
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->value('last_active_at');

    expect($updatedLastActiveAt)->not->toBeNull()
        ->and(Carbon::parse((string) $updatedLastActiveAt)->isAfter($previousLastActiveAt))->toBeTrue();
});

test('非所有者工作区成员仍接收AI助手模型选项在收件箱', function () {
    [$workspace] = createWorkspaceWithOwner();
    $provider = createWorkspaceAiProvider($workspace);
    $model = createWorkspaceAiModel($provider);

    foreach (['admin', 'operator'] as $role) {
        $member = User::factory()->create();
        $member->workspaces()->attach($workspace, ['role' => $role]);

        $this->actingAs($member)
            ->get(route('workspace.inbox.show', ['slug' => $workspace->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox')
                ->where('canManageAi', false)
                ->has('aiAssistantLlmModelOptions', 1)
                ->where('aiAssistantLlmModelOptions.0.value', (string) $model->id)
            );
    }
});

test('收件箱只显示非已删除网页频道用于当前工作区', function () {
    [$workspace, $user] = createWorkspaceWithOwner();
    $otherWorkspace = Workspace::factory()->create();

    $enabledChannel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '官网主站',
    ]);

    $secondChannel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '帮助中心',
    ]);

    Channel::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'name' => '其他工作区网站',
    ]);

    $deletedChannel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '已删除网站',
    ]);
    $deletedChannel->delete();

    $this->actingAs($user)
        ->get(route('workspace.inbox.show', ['slug' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->has('enabled_web_channels', 2)
            ->where('enabled_web_channels.0.id', (string) $enabledChannel->id)
            ->where('enabled_web_channels.0.name', '官网主站')
            ->where('enabled_web_channels.1.id', (string) $secondChannel->id)
            ->where('enabled_web_channels.0.type_label', '网站')
        );
});
