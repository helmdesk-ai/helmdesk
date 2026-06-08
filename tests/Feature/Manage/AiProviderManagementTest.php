<?php

use App\Enums\AiModelPurpose;
use App\Enums\AiProviderProtocol;
use App\Enums\UserPermission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\AiProvider\AiProviderCatalog;
use App\Services\AiRuntime\GoAiRuntimeBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
});

/**
 * 把 Go AI 运行时桥接换成固定成功的假实现，用于测试「测试连通」端点而不发真实请求。
 */
function fakeSuccessfulAiRuntimeBridge(): void
{
    $bridge = Mockery::mock(GoAiRuntimeBridge::class);
    $bridge->shouldReceive('checkProviderConnection')
        ->andReturn([
            'success' => true,
            'supported' => true,
            'code' => 'check.succeeded',
            'message' => 'runtime check succeeded',
            'warnings' => [],
        ]);
    app()->instance(GoAiRuntimeBridge::class, $bridge);
}

/**
 * 显式构造一个带活跃 LLM 模型、凭据完整的 OpenAI 供应商。
 */
function createOpenAiProviderWithModel(string $slug = 'openai-test'): AiProvider
{
    $provider = AiProvider::query()->create([
        'brand' => 'openai',
        'slug' => $slug,
        'name' => 'OpenAI',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'openai',
        'credentials' => ['key' => 'sk-test'],
        'credential_fields' => [[
            'field' => 'key',
            'label' => 'API Key',
            'type' => 'password',
            'required' => true,
            'secret' => true,
        ]],
    ]);

    makeAiModel(AiModelPurpose::ReceptionChat, $provider);

    return $provider->fresh(['models']);
}

// ---------------------------------------------------------------------------
// 访问控制与列表
// ---------------------------------------------------------------------------

test('访客不能访问 AI 供应商设置', function () {
    $this->get(route('admin.manage.ai.providers.index'))
        ->assertRedirect('/login');
});

test('有系统设置查看权限的用户可以访问 AI 供应商设置', function () {
    $viewer = User::factory()->create([
        'permissions' => [UserPermission::SystemSettingsView->value],
    ]);
    $userWithoutPermission = User::factory()->create([
        'permissions' => [],
    ]);

    $this->actingAs($viewer)
        ->get(route('admin.manage.ai.providers.index'))
        ->assertOk();

    $this->actingAs($userWithoutPermission)
        ->get(route('admin.manage.ai.providers.index'))
        ->assertForbidden();
});

test('新系统初始没有任何 AI 供应商', function () {
    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('systemSettings/aiProviders/Index')
            ->has('providers', 0));
});

test('新增页下发品牌目录选项', function () {
    $expectedCount = count(app(AiProviderCatalog::class)->brandOptions());

    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('systemSettings/aiProviders/Create')
            ->has('brand_options', $expectedCount)
            ->has('brand_options.0.brand')
            ->has('brand_options.0.credential_fields')
            ->where('brand_options', function ($options) {
                $brands = collect($options)->pluck('brand');
                expect($brands)->toContain('deepseek')->toContain('custom-openai');

                return true;
            }));
});

test('列表展示供应商及凭据完整度', function () {
    $provider = makeUsableAiProvider(['name' => 'OpenAI 主账号']);

    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers', 1)
            ->where('providers.0.name', $provider->name)
            ->where('providers.0.has_complete_credentials', true)
            ->etc());
});

// ---------------------------------------------------------------------------
// 新增页与按品牌创建
// ---------------------------------------------------------------------------

test('新增页下发品牌目录', function () {
    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('systemSettings/aiProviders/Create')
            ->has('brand_options'));
});

test('按内置品牌创建供应商且不再自动播种模型', function () {
    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'deepseek',
            'name' => '主要 DeepSeek',
            'configuration' => ['key' => 'sk-deepseek'],
        ])
        ->assertRedirect(route('admin.manage.ai.providers.index'));

    $provider = AiProvider::query()->where('brand', 'deepseek')->firstOrFail();

    expect($provider->name)->toBe('主要 DeepSeek')
        ->and($provider->protocol)->toBe(AiProviderProtocol::OpenAI)
        ->and($provider->credentials['key'])->toBe('sk-deepseek')
        // 品牌预设的默认 base_uri 应被合并进凭据后保存。
        ->and($provider->credentials['base_uri'])->toBe('https://api.deepseek.com')
        ->and($provider->hasCompleteCredentials())->toBeTrue()
        // 纯凭据创建：不再自动播种任何模型。
        ->and($provider->models()->count())->toBe(0);
});

test('同一品牌可以重复添加多个供应商', function () {
    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'openai',
            'name' => 'OpenAI 一号',
            'configuration' => ['key' => 'sk-one'],
        ])
        ->assertRedirect();

    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'openai',
            'name' => 'OpenAI 二号',
            'configuration' => ['key' => 'sk-two'],
        ])
        ->assertRedirect();

    expect(AiProvider::query()->where('brand', 'openai')->count())->toBe(2);
});

test('自定义品牌必须填写名称', function () {
    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'custom-openai',
            'configuration' => [
                'key' => 'sk-custom',
                'base_uri' => 'https://example.com/v1',
            ],
        ])
        ->assertSessionHasErrors(['name']);
});

test('可以创建自定义品牌供应商', function () {
    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'custom-openai',
            'name' => 'My Custom Provider',
            'configuration' => [
                'key' => 'sk-custom',
                'base_uri' => 'https://example.com/v1',
            ],
        ])
        ->assertRedirect();

    $provider = AiProvider::query()->where('name', 'My Custom Provider')->firstOrFail();

    expect($provider->brand)->toBe('custom-openai')
        ->and($provider->protocol)->toBe(AiProviderProtocol::OpenAI);
});

test('创建时凭据可留空，凭据完整度据此为 false', function () {
    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'openai',
            'name' => 'OpenAI 无凭据',
            'configuration' => ['key' => ''],
        ])
        ->assertRedirect(route('admin.manage.ai.providers.index'));

    $provider = AiProvider::query()->where('name', 'OpenAI 无凭据')->firstOrFail();

    expect($provider->credentials)->toBeNull()
        ->and($provider->hasCompleteCredentials())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 编辑与凭据更新
// ---------------------------------------------------------------------------

test('编辑页渲染供应商数据', function () {
    $provider = makeUsableAiProvider(['name' => '待编辑']);

    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.edit', ['provider' => $provider->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('systemSettings/aiProviders/Edit')
            ->where('provider.name', '待编辑'));
});

test('可以配置供应商凭据', function () {
    $provider = createOpenAiProviderWithModel();

    $this->actingAs($this->user)
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug]), [
            'name' => $provider->name,
            'configuration' => ['key' => 'sk-rotated-key'],
        ])
        ->assertRedirect();

    expect($provider->fresh()->credentials['key'])->toBe('sk-rotated-key');
});

test('单独更新 API Key 时保留已有端点配置', function () {
    $provider = AiProvider::query()->create([
        'brand' => 'deepseek',
        'slug' => 'deepseek-single-key-update',
        'name' => 'DeepSeek Main',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'deepseek',
        'credentials' => [
            'key' => 'sk-current',
            'base_uri' => 'https://api.deepseek.com',
        ],
        'credential_fields' => [
            ['field' => 'key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true],
            ['field' => 'base_uri', 'label' => 'Base URI', 'type' => 'url', 'required' => true],
        ],
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug]), [
            'name' => $provider->name,
            'configuration' => ['key' => 'sk-rotated'],
        ])
        ->assertRedirect();

    $provider->refresh();
    expect($provider->credentials['key'])->toBe('sk-rotated')
        ->and($provider->credentials['base_uri'])->toBe('https://api.deepseek.com');
});

test('更新凭据时空 secret 保留原值', function () {
    $provider = AiProvider::query()->create([
        'brand' => 'openai',
        'slug' => 'keep-secret-provider',
        'name' => '旧名',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'openai',
        'credentials' => ['key' => 'old-key'],
        'credential_fields' => [[
            'field' => 'key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true,
        ]],
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug]), [
            'name' => $provider->name,
            'configuration' => ['key' => ''],
        ])
        ->assertRedirect();

    expect($provider->fresh()->credentials['key'])->toBe('old-key');
});

test('更新凭据校验必填字段', function () {
    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'no-creds-provider',
        'name' => 'No Creds Provider',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'openai',
        'credentials' => null,
        'credential_fields' => [[
            'field' => 'key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true,
        ]],
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug]), [
            'name' => $provider->name,
            'configuration' => ['key' => ''],
        ])
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors(['configuration.key']);
});

// ---------------------------------------------------------------------------
// 清空凭据与删除
// ---------------------------------------------------------------------------

test('可以清空供应商凭据', function () {
    $provider = makeUsableAiProvider();

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.edit', ['provider' => $provider->slug]))
        ->delete(route('admin.manage.ai.providers.clear-credentials', ['provider' => $provider->slug]))
        ->assertRedirect();

    expect($provider->fresh()->credentials)->toBeNull();
});

test('删除供应商及其模型', function () {
    $provider = makeUsableAiProvider();
    $model = makeAiModel(AiModelPurpose::ReceptionChat, $provider);

    $this->actingAs($this->user)
        ->delete(route('admin.manage.ai.providers.destroy', ['provider' => $provider->slug]))
        ->assertRedirect(route('admin.manage.ai.providers.index'));

    expect(AiProvider::query()->whereKey($provider->id)->exists())->toBeFalse()
        ->and(AiModel::query()->whereKey($model->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 连通性检查
// ---------------------------------------------------------------------------

test('存在活跃 LLM 模型时可以测试供应商连通', function () {
    fakeSuccessfulAiRuntimeBridge();
    $provider = createOpenAiProviderWithModel();

    $this->actingAs($this->user)
        ->postJson(route('admin.manage.ai.providers.check', ['provider' => $provider->slug]), [
            'configuration' => ['key' => 'sk-test'],
        ])
        ->assertOk()
        ->assertJson(['success' => true]);
});

test('供应商没有活跃 LLM 时连通检查返回 no_model', function () {
    $provider = makeUsableAiProvider();

    $this->actingAs($this->user)
        ->postJson(route('admin.manage.ai.providers.check', ['provider' => $provider->slug]), [
            'configuration' => ['key' => 'sk-test'],
        ])
        ->assertOk()
        ->assertJson([
            'success' => false,
            'message' => __('ai.check_no_model'),
        ]);
});
