<?php

use App\Enums\TranslationProviderType;
use App\Models\ReceptionPlan;
use App\Models\TranslationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = $this->createUserWithWorkspace();
});

function workspaceTranslationProvider(string $slug = 'google-tr'): TranslationProvider
{
    return TranslationProvider::factory()
        ->create([
            'slug' => $slug,
            'credentials' => ['api_key' => 'real-key'],
        ]);
}

// ---------------------------------------------------------------------------
// 访问控制
// ---------------------------------------------------------------------------

test('未登录用户被重定向到登录页', function () {
    $this->get(route('workspace.manage.translation.providers.index'))
        ->assertRedirect('/login');
});

test('非 owner 角色无法访问翻译供应商设置', function () {
    $admin = User::factory()->create();

    $operator = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('workspace.manage.translation.providers.index'))
        ->assertForbidden();

    $this->actingAs($operator)
        ->get(route('workspace.manage.translation.providers.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 列表页
// ---------------------------------------------------------------------------

test('owner 可以查看翻译供应商列表页', function () {
    workspaceTranslationProvider();

    $this->actingAs($this->user)
        ->get(route('workspace.manage.translation.providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaceSettings/translationProviders/Index')
            ->has('providers', 1)
            ->has('protocol_options', count(TranslationProviderType::cases()))
            ->where('providers.0.slug', 'google-tr')
            ->where('providers.0.icon', 'google')
            ->where('providers.0.has_complete_credentials', true)
            ->where('providers.0.credential_values.api_key', null)
            ->whereType('providers.0.credential_masks.api_key', 'string')
        );
});

// ---------------------------------------------------------------------------
// 创建
// ---------------------------------------------------------------------------

test('创建新的翻译供应商后保持待配置状态', function () {
    $this->actingAs($this->user)
        ->post(route('workspace.manage.translation.providers.store'), [
            'name' => 'My Google',
            'protocol' => TranslationProviderType::GoogleTranslate->value,
            'configuration' => ['api_key' => 'created-key'],
        ])
        ->assertRedirect();

    $provider = $this->workspace->translationProviders()->first();
    expect($provider)->not->toBeNull()
        ->and($provider->is_builtin)->toBeFalse()
        ->and($provider->credentials)->toBe(['api_key' => 'created-key']);
});

test('新建表单可以用未保存的凭据测试翻译供应商', function () {
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => '你好', 'detectedSourceLanguage' => 'en'],
                ],
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('workspace.manage.translation.providers.check-new'), [
            'text' => 'Hello',
            'target_lang' => 'zh-CN',
            'protocol' => TranslationProviderType::GoogleTranslate->value,
            'configuration' => ['api_key' => 'draft-key'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('result.text', '你好');
});

// ---------------------------------------------------------------------------
// 更新凭据
// ---------------------------------------------------------------------------

test('更新凭据时 secret 字段提交空值会保留原值', function () {
    $provider = workspaceTranslationProvider();

    $this->actingAs($this->user)
        ->put(route('workspace.manage.translation.providers.update', ['provider' => $provider->slug,
        ]), [
            'name' => $provider->name,
            'configuration' => ['api_key' => ''],
        ])
        ->assertRedirect();

    expect($provider->fresh()->credentials)->toBe(['api_key' => 'real-key']);
});

test('更新凭据时提交新值会覆盖', function () {
    $provider = workspaceTranslationProvider();

    $this->actingAs($this->user)
        ->put(route('workspace.manage.translation.providers.update', ['provider' => $provider->slug,
        ]), [
            'name' => 'Updated Google',
            'configuration' => ['api_key' => 'new-key'],
        ])
        ->assertRedirect();

    expect($provider->fresh()->credentials)->toBe(['api_key' => 'new-key'])
        ->and($provider->fresh()->name)->toBe('Updated Google');
});

test('更新凭据校验 required 字段', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => null,
    ]);

    $this->actingAs($this->user)
        ->from(route('workspace.manage.translation.providers.index'))
        ->put(route('workspace.manage.translation.providers.update', ['provider' => $provider->slug,
        ]), [
            'name' => $provider->name,
            'configuration' => ['api_key' => ''],
        ])
        ->assertRedirect(route('workspace.manage.translation.providers.index'))
        ->assertSessionHasErrors(['configuration.api_key']);
});

// ---------------------------------------------------------------------------
// 清空凭据
// ---------------------------------------------------------------------------

test('清空凭据后供应商不再具备完整凭据', function () {
    $provider = workspaceTranslationProvider();

    $this->actingAs($this->user)
        ->delete(route('workspace.manage.translation.providers.clear-credentials', ['provider' => $provider->slug,
        ]))
        ->assertRedirect();

    $fresh = $provider->fresh();
    expect($fresh->credentials)->toBeNull()
        ->and($fresh->hasCompleteCredentials())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 删除
// ---------------------------------------------------------------------------

test('删除非内置 provider 成功', function () {
    $provider = TranslationProvider::factory()->create([
        'is_builtin' => false,
    ]);

    $this->actingAs($this->user)
        ->delete(route('workspace.manage.translation.providers.destroy', ['provider' => $provider->slug,
        ]))
        ->assertRedirect();

    expect(TranslationProvider::find($provider->id))->toBeNull();
});

test('被接待方案引用的 provider 不允许删除', function () {
    $provider = TranslationProvider::factory()->create([
        'is_builtin' => false,
    ]);
    ReceptionPlan::factory()->create([
        'translation_config' => [
            'enabled' => true,
            'failure_mode' => 'skip',
            'provider_id' => $provider->id,
        ],
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->from(route('workspace.manage.translation.providers.index'))
        ->delete(route('workspace.manage.translation.providers.destroy', ['provider' => $provider->slug,
        ]))
        ->assertSessionHasErrors('toast');

    expect(TranslationProvider::find($provider->id))->not->toBeNull();
});

test('内置 provider 不允许删除', function () {
    $provider = TranslationProvider::factory()->create([
        'is_builtin' => true,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->from(route('workspace.manage.translation.providers.index'))
        ->delete(route('workspace.manage.translation.providers.destroy', ['provider' => $provider->slug,
        ]))
        ->assertSessionHasErrors('toast');

    expect(TranslationProvider::find($provider->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 测试连通
// ---------------------------------------------------------------------------

test('测试翻译连通 - 成功', function () {
    $provider = workspaceTranslationProvider();

    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => '你好', 'detectedSourceLanguage' => 'en'],
                ],
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('workspace.manage.translation.providers.check', ['provider' => $provider->slug,
        ]), [
            'text' => 'Hello',
            'target_lang' => 'zh-CN',
            'source_lang' => 'en',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('result.text', '你好')
        ->assertJsonPath('result.source_lang', 'en');
});

test('测试翻译连通 - 失败时返回 success=false', function () {
    $provider = workspaceTranslationProvider();

    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key not valid'],
        ], 400),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('workspace.manage.translation.providers.check', ['provider' => $provider->slug,
        ]), [
            'text' => 'Hello',
            'target_lang' => 'zh-CN',
        ])
        ->assertOk()
        ->assertJsonPath('success', false);
});

test('测试连通时允许临时覆盖未保存的凭据', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'stale-key'],
    ]);

    $capturedUrl = null;

    Http::fake([
        'translation.googleapis.com/*' => function (Request $request) use (&$capturedUrl) {
            $capturedUrl = $request->url();

            return Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => '你好', 'detectedSourceLanguage' => 'en'],
                    ],
                ],
            ]);
        },
    ]);

    $this->actingAs($this->user)
        ->postJson(route('workspace.manage.translation.providers.check', ['provider' => $provider->slug,
        ]), [
            'text' => 'Hello',
            'target_lang' => 'zh-CN',
            'configuration' => ['api_key' => 'fresh-key'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($capturedUrl)->toContain('key=fresh-key');
    expect($provider->fresh()->credentials)->toBe(['api_key' => 'stale-key']);
});
