<?php

use App\Enums\AiProviderProtocol;
use App\Enums\UserPermission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Models\User;
use App\Services\AiProvider\AiProviderCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
    fakeSystemAiRuntimeBridge();
});

function fakeSystemAiRuntimeBridge(): void
{
    config()->set('services.go_runtime.base_url', 'http://127.0.0.1:65535');
    config()->set('services.go_runtime.bridge_token', 'test-bridge-token');

    Http::fake(function (HttpRequest $request) {
        $payload = $request->data();
        $provider = is_array($payload['provider'] ?? null) ? $payload['provider'] : [];
        $credentials = is_array($provider['credentials'] ?? null) ? $provider['credentials'] : [];
        $credentialFields = collect($provider['credential_fields'] ?? [])
            ->filter(fn ($field): bool => is_array($field) && ($field['required'] ?? false))
            ->pluck('field')
            ->filter(fn ($field): bool => is_string($field) && $field !== '')
            ->values();

        $missingFields = $credentialFields
            ->filter(fn (string $field): bool => blank($credentials[$field] ?? null))
            ->values()
            ->all();

        $hasActiveLlmModel = collect($provider['models'] ?? [])
            ->contains(fn ($model): bool => is_array($model)
                && ($model['type'] ?? null) === 'llm'
                && ($model['is_active'] ?? false) === true);

        if (str_ends_with($request->url(), '/check')) {
            if (! $hasActiveLlmModel) {
                return Http::response([
                    'success' => false,
                    'supported' => true,
                    'code' => 'check.no_active_llm',
                    'message' => 'no active llm model is available for runtime check',
                ]);
            }

            if ($missingFields !== []) {
                $joined = implode(', ', $missingFields);

                return Http::response([
                    'success' => false,
                    'supported' => true,
                    'code' => 'check.missing_credentials',
                    'params' => ['fields' => $joined],
                    'message' => 'missing required credentials: '.$joined,
                ]);
            }

            return Http::response([
                'success' => true,
                'supported' => true,
                'code' => 'check.succeeded',
                'message' => 'runtime check succeeded',
            ]);
        }

        if (str_ends_with($request->url(), '/validate')) {
            if (($payload['mode'] ?? null) === 'model-save' && $missingFields !== []) {
                return Http::response([
                    'success' => false,
                    'supported' => true,
                    'code' => 'validate.incomplete_credentials',
                    'message' => 'provider credentials are incomplete; runtime validation cannot be completed',
                ]);
            }

            if ($missingFields !== []) {
                $joined = implode(', ', $missingFields);

                return Http::response([
                    'success' => false,
                    'supported' => true,
                    'code' => 'validate.missing_credentials',
                    'params' => ['fields' => $joined],
                    'message' => 'missing required credentials: '.$joined,
                ]);
            }

            if (! $hasActiveLlmModel) {
                return Http::response([
                    'success' => false,
                    'supported' => true,
                    'code' => 'validate.no_active_model',
                    'message' => 'no active llm model is configured; runtime validation cannot be completed',
                ]);
            }
        }

        return Http::response([
            'success' => true,
            'supported' => true,
            'code' => 'validate.provider_accepted',
            'message' => 'provider configuration accepted by runtime',
        ]);
    });
}

/**
 * 显式构造一个带活跃 LLM 模型的 OpenAI 供应商，供需要「可用模型」的用例复用。
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
        'is_builtin' => true,
        'sort_order' => 0,
    ]);

    $provider->models()->create([
        'model_id' => 'gpt-test',
        'name' => 'GPT Test',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => true,
        'sort_order' => 0,
    ]);

    return $provider->fresh(['models']);
}

// ---------------------------------------------------------------------------
// Access control & list
// ---------------------------------------------------------------------------

test('访客用户不能访问系统AI提供商设置', function () {
    $this->get(route('admin.manage.ai.providers.index'))
        ->assertRedirect('/login');
});

test('有系统设置查看权限的用户可以访问 AI 提供商设置', function () {
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

test('新系统初始没有任何AI供应商', function () {
    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('systemSettings/aiProviders/Index')
            ->has('providers', 0)
            ->has('brand_options'));
});

test('列表页下发品牌目录选项', function () {
    $expectedCount = count(app(AiProviderCatalog::class)->brandOptions());

    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('systemSettings/aiProviders/Index')
            ->has('brand_options', $expectedCount)
            ->has('brand_options.0.brand')
            ->has('brand_options.0.credential_fields')
            ->where('brand_options', function ($options) {
                $brands = collect($options)->pluck('brand');
                // 既覆盖映射型内置品牌，也覆盖自定义入口品牌。
                expect($brands)->toContain('deepseek')->toContain('custom-openai');

                return true;
            }));
});

test('单租户下超级管理员可以看到全部供应商', function () {
    createOpenAiProviderWithModel('mine');

    [$otherSystem] = createSystemWithOwner();
    createOpenAiProviderWithModel('theirs');

    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers', 2)
            ->where('providers', function ($providers) {
                expect(collect($providers)->pluck('slug')->all())
                    ->toContain('mine')
                    ->toContain('theirs');

                return true;
            }));
});

// ---------------------------------------------------------------------------
// Provider creation from brand catalog
// ---------------------------------------------------------------------------

test('超级管理员可以从内置品牌一步创建供应商并带内置模型', function () {
    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'deepseek',
            'name' => '主要 DeepSeek',
            'configuration' => [
                'key' => 'sk-deepseek',
            ],
        ])
        ->assertRedirect();

    $provider = AiProvider::query()
        ->where('brand', 'deepseek')
        ->firstOrFail();

    expect($provider->is_builtin)->toBeTrue();
    expect($provider->name)->toBe('主要 DeepSeek');
    expect($provider->protocol)->toBe(AiProviderProtocol::OpenAI);
    expect($provider->credentials['key'])->toBe('sk-deepseek');
    // 品牌预设的默认 base_uri 应被合并进凭据后保存。
    expect($provider->credentials['base_uri'])->toBe('https://api.deepseek.com');

    $modelIds = $provider->models()->pluck('model_id')->all();
    expect($modelIds)
        ->toContain('deepseek-v4-pro')
        ->toContain('deepseek-v4-flash');
    // 内置品牌建出来的模型都应标记为内置。
    expect($provider->models()->where('is_builtin', false)->count())->toBe(0);
    expect($provider->models()->count())->toBeGreaterThan(0);
});

test('超级管理员可以从 OpenRouter 内置品牌一步创建供应商并带内置模型', function () {
    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'openrouter',
            'name' => '主要 OpenRouter',
            'configuration' => [
                'key' => 'sk-or-test',
            ],
        ])
        ->assertRedirect();

    $provider = AiProvider::query()
        ->where('brand', 'openrouter')
        ->firstOrFail();

    expect($provider->is_builtin)->toBeTrue();
    expect($provider->name)->toBe('主要 OpenRouter');
    // OpenRouter 底层走 OpenAI agentic 协议。
    expect($provider->protocol)->toBe(AiProviderProtocol::OpenAI);
    expect($provider->credentials['key'])->toBe('sk-or-test');
    // 品牌预设的默认 base_uri 应被合并进凭据后保存。
    expect($provider->credentials['base_uri'])->toBe('https://openrouter.ai/api/v1');

    $modelIds = $provider->models()->pluck('model_id')->all();
    expect($modelIds)
        ->toContain('openai/gpt-5.5')
        ->toContain('deepseek/deepseek-v4-flash')
        ->toContain('openai/text-embedding-3-small');
    expect($provider->models()->where('is_builtin', false)->count())->toBe(0);
});

test('同一品牌可以重复添加多个供应商', function () {
    $payload = [
        'brand' => 'openai',
        'name' => 'OpenAI 一号',
        'configuration' => ['key' => 'sk-one'],
    ];

    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), $payload)
        ->assertRedirect();

    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'openai',
            'name' => 'OpenAI 二号',
            'configuration' => ['key' => 'sk-two'],
        ])
        ->assertRedirect();

    expect(AiProvider::query()
        ->where('brand', 'openai')
        ->count())->toBe(2);
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

test('超级管理员可以创建自定义品牌供应商', function () {
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

    $provider = AiProvider::query()
        ->where('name', 'My Custom Provider')
        ->firstOrFail();

    expect($provider->brand)->toBe('custom-openai');
    expect($provider->is_builtin)->toBeFalse();
    expect($provider->protocol)->toBe(AiProviderProtocol::OpenAI);
});

test('创建供应商时缺少必填凭据报校验错误', function () {
    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->post(route('admin.manage.ai.providers.store'), [
            'brand' => 'openai',
            'name' => 'OpenAI',
            'configuration' => [
                'key' => '',
            ],
        ])
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors(['configuration.key']);
});

// ---------------------------------------------------------------------------
// Credentials
// ---------------------------------------------------------------------------

test('超级管理员可以配置提供商凭证', function () {
    $provider = createOpenAiProviderWithModel();

    $this->actingAs($this->user)
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug,
        ]), [
            'configuration' => [
                'key' => 'sk-systemContext-key',
            ],
        ])
        ->assertRedirect();

    $provider->refresh();
    expect($provider->credentials['key'])->toBe('sk-systemContext-key');
});

test('单独更新API Key时保留已有端点配置', function () {
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
        'is_builtin' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug,
        ]), [
            'configuration' => [
                'key' => 'sk-rotated',
            ],
        ])
        ->assertRedirect();

    $provider->refresh();
    expect($provider->credentials['key'])->toBe('sk-rotated')
        ->and($provider->credentials['base_uri'])->toBe('https://api.deepseek.com');
});

test('创建后不能通过凭据更新修改 Base URI', function () {
    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'locked-endpoint-provider',
        'name' => 'Locked Endpoint Provider',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'openai',
        'credentials' => [
            'key' => 'sk-current',
            'base_uri' => 'https://old.example.com/v1',
        ],
        'credential_fields' => [
            ['field' => 'key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true],
            ['field' => 'base_uri', 'label' => 'Base URI', 'type' => 'url', 'required' => true],
        ],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug,
        ]), [
            'configuration' => [
                'base_uri' => 'https://new.example.com/v1',
            ],
        ])
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors(['configuration.base_uri']);

    expect($provider->fresh()->credentials['base_uri'])->toBe('https://old.example.com/v1');
});

test('更新凭证校验必需字段', function () {
    // 未配置凭据的供应商，清空必填 key 时应触发字段级校验错误。
    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'no-creds-provider',
        'name' => 'No Creds Provider',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'openai',
        'credentials' => null,
        'credential_fields' => [[
            'field' => 'key',
            'label' => 'API Key',
            'type' => 'password',
            'required' => true,
            'secret' => true,
        ]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->put(route('admin.manage.ai.providers.update', ['provider' => $provider->slug,
        ]), [
            'configuration' => [
                'key' => '',
            ],
        ])
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors(['configuration.key']);
});

// ---------------------------------------------------------------------------
// Provider deletion
// ---------------------------------------------------------------------------

test('超级管理员可以删除任意供应商', function () {
    $provider = createOpenAiProviderWithModel();

    $this->actingAs($this->user)
        ->delete(route('admin.manage.ai.providers.destroy', ['provider' => $provider->slug,
        ]))
        ->assertRedirect();

    expect(AiProvider::query()->find($provider->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Model CRUD
// ---------------------------------------------------------------------------

test('超级管理员可以添加自定义模型到提供商在其系统', function () {
    $provider = createOpenAiProviderWithModel();

    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.models.store', ['provider' => $provider->slug,
        ]), [
            'model_id' => 'custom-model',
            'name' => 'Custom Model',
            'type' => 'llm',
        ])
        ->assertRedirect();

    $model = $provider->models()->where('model_id', 'custom-model')->first();
    expect($model)->not->toBeNull();
    expect($model->name)->toBe('Custom Model');
    expect($model->is_builtin)->toBeFalse();
});

test('超级管理员可以切换模型活跃状态', function () {
    $provider = createOpenAiProviderWithModel();
    // 额外建一个 LLM，确保切换被测模型时仍保留至少一个活跃 LLM。
    $provider->models()->create([
        'model_id' => 'gpt-secondary',
        'name' => 'GPT Secondary',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 1,
    ]);
    $model = $provider->models()->where('model_id', 'gpt-secondary')->firstOrFail();

    $this->actingAs($this->user)
        ->put(route('admin.manage.ai.models.toggle', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect();

    $model->refresh();
    expect($model->is_active)->toBeFalse();
});

test('超级管理员可以更新内置模型显示名称且保持内置状态', function () {
    $provider = createOpenAiProviderWithModel();
    $model = $provider->models()->where('is_builtin', true)->firstOrFail();

    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.models.store', ['provider' => $provider->slug,
        ]), [
            'model_id' => $model->model_id,
            'name' => 'Renamed Built-in Model',
            'type' => $model->type,
        ])
        ->assertRedirect();

    $model->refresh();
    expect($model->name)->toBe('Renamed Built-in Model')
        ->and($model->is_builtin)->toBeTrue();
});

test('超级管理员可以删除自定义模型', function () {
    $provider = createOpenAiProviderWithModel();

    $model = $provider->models()->create([
        'model_id' => 'custom-model-to-delete',
        'name' => 'Custom Model To Delete',
        'type' => 'llm',
        'is_active' => false,
        'is_builtin' => false,
        'sort_order' => 999,
    ]);

    $this->actingAs($this->user)
        ->delete(route('admin.manage.ai.models.destroy', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect();

    expect(AiModel::query()->find($model->id))->toBeNull();
});

test('不能禁用最后活跃LLM模型', function () {
    $provider = buildReferenceProvider();
    $provider->models()
        ->where('type', 'embedding')
        ->delete();

    $model = $provider->models->firstOrFail();

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->put(route('admin.manage.ai.models.toggle', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');
});

test('不能删除最后活跃LLM模型', function () {
    $provider = buildReferenceProvider();
    $provider->models()
        ->where('type', 'embedding')
        ->delete();

    $model = $provider->models->firstOrFail();

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.ai.models.destroy', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');
});

// ---------------------------------------------------------------------------
// Reference protections
// ---------------------------------------------------------------------------

function buildReferenceProvider(): AiProvider
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'ref-provider',
        'name' => 'Ref Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Ref Model',
        'model_id' => 'ref-model',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    return $provider->fresh(['models']);
}

function buildKnowledgeBaseReferenceProvider(string $modelType = 'embedding'): AiProvider
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'kb-ref-provider-'.$modelType,
        'name' => 'KB Ref Provider',
        'protocol' => 'openai',
        'credentials' => ['key' => 'test'],
        'credential_fields' => [['field' => 'key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $model = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'KB Ref Model',
        'model_id' => 'kb-ref-model-'.$modelType,
        'type' => $modelType,
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $embeddingModel = $model;
    $rerankModel = null;

    if ($modelType === 'rerank') {
        $embeddingModel = AiModel::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'KB Ref Embedding Model',
            'model_id' => 'kb-ref-model-embedding',
            'type' => 'embedding',
            'is_active' => true,
            'is_builtin' => false,
            'sort_order' => 1,
        ]);
        $rerankModel = $model;
    }

    $systemContext->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_rerank_model_id' => $rerankModel?->id,
    ]);

    return $provider->fresh(['models']);
}

/**
 * 构造一个引用指定 AI 模型的已发布接待方案版本，模拟运行时正在使用该模型的场景。
 */
function referenceReceptionPlanVersion(string $modelId): ReceptionPlanVersion
{
    $plan = ReceptionPlan::factory()->create([
        'name' => '引用接待方案-'.Str::lower(Str::random(6)),
    ]);

    return ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel($modelId)
        ->create();
}

test('不能删除被接待方案版本引用的模型', function () {
    $provider = buildReferenceProvider();
    $model = $provider->models->first();

    referenceReceptionPlanVersion($model->id);

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.ai.models.destroy', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');
});

test('不能删除被知识库引用的模型', function () {
    $provider = buildKnowledgeBaseReferenceProvider();
    $model = $provider->models->first();

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.ai.models.destroy', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');
});

test('不能删除被知识库引用的 ReRank 模型', function () {
    $provider = buildKnowledgeBaseReferenceProvider('rerank');
    $model = $provider->models->firstWhere('type', 'rerank');

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.ai.models.destroy', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');
});

test('不能禁用被接待方案版本引用的模型', function () {
    $provider = buildReferenceProvider();
    $model = $provider->models->first();

    referenceReceptionPlanVersion($model->id);

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->put(route('admin.manage.ai.models.toggle', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');

    expect($model->fresh()->is_active)->toBeTrue();
});

test('不能禁用被知识库引用的模型', function () {
    $provider = buildKnowledgeBaseReferenceProvider();
    $model = $provider->models->first();

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->put(route('admin.manage.ai.models.toggle', ['provider' => $provider->slug,
            'model' => $model->id,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');

    expect($model->fresh()->is_active)->toBeTrue();
});

test('不能删除被接待方案版本引用的提供商', function () {
    $provider = buildReferenceProvider();
    $model = $provider->models->first();

    referenceReceptionPlanVersion($model->id);

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.ai.providers.destroy', ['provider' => $provider->slug,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');
});

test('不能删除被知识库引用了模型的提供商', function () {
    $provider = buildKnowledgeBaseReferenceProvider();

    $this->actingAs($this->user)
        ->from(route('admin.manage.ai.providers.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.ai.providers.destroy', ['provider' => $provider->slug,
        ]))
        ->assertRedirect(route('admin.manage.ai.providers.index'))
        ->assertSessionHasErrors('toast');
});

// ---------------------------------------------------------------------------
// Connection check
// ---------------------------------------------------------------------------

test('检查连接返回no_model当提供商没有活跃LLM', function () {
    $provider = AiProvider::query()->create([
        'brand' => 'azure-openai',
        'slug' => 'azure-no-model',
        'name' => 'Azure OpenAI',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'azure',
        'credentials' => null,
        'credential_fields' => [
            ['field' => 'key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true],
            ['field' => 'base_uri', 'label' => 'Base URI', 'type' => 'url', 'required' => true],
        ],
        'is_builtin' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('admin.manage.ai.providers.check', ['provider' => $provider->slug,
        ]), [
            'configuration' => [
                'key' => 'azure-test-key',
                'base_uri' => 'https://example-resource.openai.azure.com',
            ],
        ])
        ->assertOk()
        ->assertJson([
            'success' => false,
            'message' => __('ai.check_no_model'),
        ]);

    Http::assertNothingSent();
});

test('检查连接接受未保存的嵌套配置当存在活跃LLM时', function () {
    $provider = AiProvider::query()->create([
        'brand' => 'azure-openai',
        'slug' => 'azure-with-model',
        'name' => 'Azure OpenAI',
        'protocol' => AiProviderProtocol::OpenAI->value,
        'icon' => 'azure',
        'credentials' => null,
        'credential_fields' => [
            ['field' => 'key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true],
            ['field' => 'base_uri', 'label' => 'Base URI', 'type' => 'url', 'required' => true],
        ],
        'is_builtin' => true,
        'sort_order' => 0,
    ]);

    $provider->models()->create([
        'name' => 'Azure Test Model',
        'model_id' => 'gpt-test',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('admin.manage.ai.providers.check', ['provider' => $provider->slug,
        ]), [
            'configuration' => [
                'key' => 'azure-test-key',
                'base_uri' => 'https://example-resource.openai.azure.com',
            ],
        ])
        ->assertOk()
        ->assertJson(['success' => true]);
});
