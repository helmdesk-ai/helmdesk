<?php

use App\Data\Reception\Plan\AutoMessagesConfigData;
use App\Data\Reception\Plan\ReceptionMessageTranslationConfigData;
use App\Enums\AiModelPurpose;
use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\Reception\ReceptionRoutingMode;
use App\Enums\ReceptionPlanVersionStatus;
use App\Enums\UserPermission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\TranslationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = $this->createUserWithSystem();
});

function createReceptionTestProvider(array $attributes = []): AiProvider
{
    return makeUsableAiProvider($attributes);
}

/**
 * Seed 一个全局接待对话 LLM 模型（模型已全局化，运行时按用途取用，方案不再引用具体模型）。
 *
 * @param  array<string, mixed>  $attributes
 */
function createReceptionTestModel(AiProvider $provider, array $attributes = []): AiModel
{
    $isActive = (bool) ($attributes['is_active'] ?? true);

    return makeAiModel(AiModelPurpose::ReceptionChat, $provider, $isActive);
}

function createReceptionTestTranslationProvider(array $attributes = []): TranslationProvider
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    return TranslationProvider::factory()
        ->create($attributes);
}

function receptionPlanStrategyPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'reception_mode' => ReceptionRoutingMode::AiFirst->value,
        'unassigned_ai_takeover_enabled' => false,
        'unassigned_ai_takeover_timeout_seconds' => 120,
        'teammate_no_response_ai_takeover_enabled' => true,
        'teammate_no_response_ai_takeover_timeout_seconds' => 300,
        'important_contact_ai_careful_reply_enabled' => true,
        'important_contact_ai_handoff_hint_enabled' => true,
        'important_contact_human_first_when_online_enabled' => false,
        'quote_visitor_message_enabled' => false,
        'handoff_available_notice' => '已为您转接人工客服，请稍等。',
        'handoff_no_teammate_notice' => '当前暂无法转接人工，我会继续为您处理。',
        'ai_unavailable_notice' => '很抱歉，AI 助手暂时无法为您服务，正在为您转接人工客服，请稍候。',
        'business_hours' => null,
    ], $overrides);
}

function receptionPlanAutoMessagesPayload(array $overrides = []): array
{
    return array_replace_recursive(AutoMessagesConfigData::DEFAULT_CONFIG, $overrides);
}

function receptionPlanTranslationPayload(array $overrides = []): array
{
    return array_replace(ReceptionMessageTranslationConfigData::DEFAULT_CONFIG, $overrides);
}

test('接待方案默认自动回复配置全部开启并带默认文案', function () {
    expect(AutoMessagesConfigData::DEFAULT_CONFIG)
        ->toMatchArray([
            'ai_welcome' => ['enabled' => true, 'message' => '您好，我是{{display_name}}，请问有什么可以帮您？'],
            'teammate_joined' => ['enabled' => true, 'message' => '您好，我是{{teammate_name}}，接下来由我为您服务。'],
            'teammate_transferred' => ['enabled' => true, 'message' => '您好，我是{{teammate_name}}，已接手本次会话。'],
        ]);
});

test('接待方案访客侧文案自动翻译默认关闭', function () {
    expect(ReceptionMessageTranslationConfigData::DEFAULT_CONFIG)
        ->toMatchArray([
            'enabled' => false,
            'failure_mode' => AutoMessageTranslationFailureMode::Skip->value,
            'provider_id' => null,
        ])
        ->and(ReceptionMessageTranslationConfigData::fromArray(null)->enabled)->toBeFalse()
        ->and(ReceptionMessageTranslationConfigData::fromArray(null)->provider_id)->toBeNull();
});

test('超级管理员可以查看接待方案列表页', function () {
    // 模型已全局化：方案不再存模型，列表只展示方案本身配置。
    createReceptionTestModel(createReceptionTestProvider());

    ReceptionPlan::factory()->create([
        'name' => '默认接待方案',
        'description' => '默认描述',
        'capabilities' => [
            [
                'name' => '订单查询',
                'description' => '',
                'instructions' => '查询订单',
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.reception.plans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reception/plans/List')
            ->has('plan_list', 1)
            ->where('plan_list.0.name', '默认接待方案')
            ->where('plan_list.0.service_scenarios_count', 1)
            ->where('plan_list.0.translation_config.enabled', false)
            ->where('plan_list.0.translation_config.provider_id', null)
            ->where('plan_list.0.strategy_config.quote_visitor_message_enabled', false)
        );
});

test('接待方案管理页下发枚举选项', function () {
    $plan = ReceptionPlan::factory()->create([
        'name' => '草稿方案',
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.reception.plans.show', ['plan' => $plan->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reception/plans/Detail')
            ->has('persona_tone_options', 3)
            ->where('message_translation_failure_mode_options.0.value', AutoMessageTranslationFailureMode::Skip->value)
            ->where('persona_tone_options.0.value', 'professional')
        );
});

test('创建接待方案时语气风格非法值会被校验拒绝', function () {
    $provider = createReceptionTestProvider();
    $model = createReceptionTestModel($provider);

    $this->actingAs($this->user)
        ->post(route('admin.manage.reception.plans.store'), [
            'name' => '非法语气方案',
            'description' => null,
            'persona_display_name' => '接待助手',
            'persona_tone' => 'sarcastic',
            'global_instructions' => null,
            'strategy_config' => receptionPlanStrategyPayload(),
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
        ])
        ->assertSessionHasErrors(['persona_tone']);
});

test('创建接待方案时语气风格必填', function () {
    $provider = createReceptionTestProvider();
    $model = createReceptionTestModel($provider);

    $this->actingAs($this->user)
        ->post(route('admin.manage.reception.plans.store'), [
            'name' => '缺语气风格方案',
            'description' => null,
            'persona_display_name' => '接待助手',
            'persona_tone' => '',
            'global_instructions' => null,
            'strategy_config' => receptionPlanStrategyPayload(),
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
        ])
        ->assertSessionHasErrors(['persona_tone']);
});

test('创建接待方案时对外昵称必填', function () {
    $provider = createReceptionTestProvider();
    $model = createReceptionTestModel($provider);

    $this->actingAs($this->user)
        ->post(route('admin.manage.reception.plans.store'), [
            'name' => '缺对外昵称方案',
            'description' => null,
            'persona_display_name' => '',
            'persona_tone' => 'concise',
            'global_instructions' => null,
            'strategy_config' => receptionPlanStrategyPayload(),
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
        ])
        ->assertSessionHasErrors(['persona_display_name']);
});

test('创建接待方案即生成初始版本快照', function () {
    // 模型已全局化：方案不再选模型，创建即编译并发布初始版本快照。
    createReceptionTestModel(createReceptionTestProvider());

    $this->actingAs($this->user)
        ->post(route('admin.manage.reception.plans.store'), [
            'name' => '初始版本方案',
            'persona_display_name' => '小 A',
            'persona_tone' => 'friendly',
            'strategy_config' => receptionPlanStrategyPayload(),
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
            'translation_config' => receptionPlanTranslationPayload(),
        ])
        ->assertRedirect();

    $plan = ReceptionPlan::query()->firstOrFail();
    $version = ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->firstOrFail();

    expect($version->version_number)->toBe(1)
        ->and($version->status)->toBe(ReceptionPlanVersionStatus::Published)
        ->and($version->compiled_config['reception_agent']['instruction'])->toContain('小 A');
});

test('超级管理员可以创建接待方案草稿', function () {
    // 模型已全局化：方案不再选模型，只保存人设 / 策略 / 自动回复 / 翻译等配置。
    createReceptionTestModel(createReceptionTestProvider());
    $strategyConfig = receptionPlanStrategyPayload([
        'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
        'unassigned_ai_takeover_enabled' => true,
        'unassigned_ai_takeover_timeout_seconds' => 90,
        'handoff_available_notice' => '正在为您转接人工客服。',
    ]);
    $translationConfig = receptionPlanTranslationPayload([
        'enabled' => true,
        'failure_mode' => AutoMessageTranslationFailureMode::SendOriginal->value,
        'provider_id' => createReceptionTestTranslationProvider()->id,
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('admin.manage.reception.plans.store'), [
            'name' => '售前接待方案',
            'description' => '负责售前咨询',
            'persona_display_name' => '小 A',
            'persona_tone' => 'friendly',
            'global_instructions' => '保持友好简洁',
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
            'translation_config' => $translationConfig,
        ]);

    $plan = ReceptionPlan::query()->firstOrFail();

    $response->assertRedirect(route('admin.manage.reception.plans.show', ['plan' => $plan->id,
    ]));

    expect($plan->name)->toBe('售前接待方案')
        ->and($plan->persona_config['display_name'])->toBe('小 A')
        ->and($plan->persona_config['tone'])->toBe('friendly')
        ->and($plan->global_instructions)->toBe('保持友好简洁')
        ->and($plan->auto_messages_config)->toBe(receptionPlanAutoMessagesPayload())
        ->and($plan->translation_config)->toBe($translationConfig)
        ->and($plan->strategy_config)->toBe($strategyConfig);
});

test('接待方案营业时间允许结束于午夜', function () {
    $provider = createReceptionTestProvider();
    $model = createReceptionTestModel($provider);
    $strategyConfig = receptionPlanStrategyPayload([
        'business_hours' => [
            'timezone' => 'Asia/Shanghai',
            'outside_hours_notice' => '当前不是人工服务时间。',
            'schedule' => [
                ['day' => 1, 'enabled' => true, 'open' => '09:00', 'close' => '00:00'],
                ['day' => 2, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 3, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 4, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 5, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 6, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 7, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.manage.reception.plans.store'), [
            'name' => '午夜营业方案',
            'description' => null,
            'persona_display_name' => '接待助手',
            'persona_tone' => 'concise',
            'global_instructions' => null,
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
        ])
        ->assertSessionHasNoErrors();

    expect(ReceptionPlan::query()->firstOrFail()->strategy_config)->toMatchArray($strategyConfig);
});

test('同一系统内方案名称必须唯一', function () {
    $provider = createReceptionTestProvider();
    $model = createReceptionTestModel($provider);

    ReceptionPlan::factory()->create([
        'name' => '已存在方案',
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.manage.reception.plans.store'), [
            'name' => '已存在方案',
            'description' => null,
            'persona_display_name' => '接待助手',
            'persona_tone' => 'concise',
            'global_instructions' => null,
            'strategy_config' => receptionPlanStrategyPayload(),
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
        ])
        ->assertSessionHasErrors(['name']);
});

test('超级管理员可以更新接待方案草稿', function () {
    // 模型已全局化：方案更新不再涉及模型选择。
    createReceptionTestModel(createReceptionTestProvider());
    $strategyConfig = receptionPlanStrategyPayload([
        'teammate_no_response_ai_takeover_enabled' => true,
        'teammate_no_response_ai_takeover_timeout_seconds' => 180,
        'business_hours' => [
            'timezone' => 'Asia/Shanghai',
            'outside_hours_notice' => '当前不是人工服务时间。',
            'schedule' => [
                ['day' => 1, 'enabled' => true, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 2, 'enabled' => true, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 3, 'enabled' => true, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 4, 'enabled' => true, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 5, 'enabled' => true, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 6, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 7, 'enabled' => false, 'open' => '09:00', 'close' => '18:00'],
            ],
        ],
    ]);

    $plan = ReceptionPlan::factory()->create([
        'name' => '原始名称',
    ]);

    $autoMessagesConfig = receptionPlanAutoMessagesPayload([
        'ai_welcome' => ['enabled' => false, 'message' => null],
        'teammate_transferred' => ['enabled' => false, 'message' => null],
    ]);
    $translationConfig = receptionPlanTranslationPayload([
        'enabled' => true,
        'failure_mode' => AutoMessageTranslationFailureMode::SendOriginal->value,
        'provider_id' => createReceptionTestTranslationProvider()->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.reception.plans.index', ['plan' => $plan->id,
        ]))
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id]), [
            'name' => '更新后的名称',
            'description' => '更新后说明',
            'persona_display_name' => '小 B',
            'persona_tone' => 'concise',
            'global_instructions' => '更新后的指引',
            'service_scenarios' => [],
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => $autoMessagesConfig,
            'translation_config' => $translationConfig,
        ])
        ->assertRedirect(route('admin.manage.reception.plans.show', ['plan' => $plan->id,
        ]));

    $plan->refresh();

    expect($plan->name)->toBe('更新后的名称')
        ->and($plan->description)->toBe('更新后说明')
        ->and($plan->persona_config['display_name'])->toBe('小 B')
        ->and($plan->persona_config['tone'])->toBe('concise')
        ->and($plan->global_instructions)->toBe('更新后的指引')
        ->and($plan->auto_messages_config)->toBe($autoMessagesConfig)
        ->and($plan->translation_config)->toBe($translationConfig)
        ->and($plan->strategy_config)->toMatchArray($strategyConfig);
});

test('保存接待方案即生成新版本快照且配置无变化时不新增版本', function () {
    $provider = createReceptionTestProvider();
    $model = createReceptionTestModel($provider);
    $task = createReceptionTestModel($provider, [
        'name' => 'Task Model',
        'model_id' => 'task-model',
        'sort_order' => 1,
    ]);

    $plan = ReceptionPlan::factory()->create([
        'name' => '保存即发布方案',
        'reception_config' => ['default_model' => ['ai_model_id' => $model->id]],
        'task_config' => ['default_model' => ['ai_model_id' => $task->id]],
    ]);

    $payload = [
        'name' => '保存即发布方案',
        'description' => '说明',
        'persona_display_name' => '小 A',
        'persona_tone' => 'friendly',
        'global_instructions' => '保持简洁',
        'service_scenarios' => [],
        'strategy_config' => receptionPlanStrategyPayload(),
        'auto_messages_config' => receptionPlanAutoMessagesPayload(),
        'translation_config' => receptionPlanTranslationPayload(),
    ];

    // 首次保存：生成 v1
    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id]), $payload)
        ->assertRedirect();

    expect(ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->count())->toBe(1);

    // 配置无变化的重复保存：不新增版本
    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id]), $payload)
        ->assertRedirect();

    expect(ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->count())->toBe(1);

    // 改动配置再保存：递增到 v2
    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id]), [
            ...$payload,
            'global_instructions' => '换一段全新的指引',
        ])
        ->assertRedirect();

    $latest = ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->orderByDesc('version_number')->first();
    expect(ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->count())->toBe(2)
        ->and($latest->version_number)->toBe(2)
        ->and($latest->compiled_config['reception_agent']['instruction'])->toContain('换一段全新的指引');
});

test('仅自动回复配置变化也会生成新版本', function () {
    createReceptionTestModel(createReceptionTestProvider());

    $plan = ReceptionPlan::factory()->create([
        'name' => '自动回复方案',
    ]);

    $payload = [
        'name' => '自动回复方案',
        'persona_display_name' => '小 A',
        'persona_tone' => 'friendly',
        'service_scenarios' => [],
        'strategy_config' => receptionPlanStrategyPayload(),
        'auto_messages_config' => receptionPlanAutoMessagesPayload(),
        'translation_config' => receptionPlanTranslationPayload(),
    ];

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id]), $payload)
        ->assertRedirect();

    expect(ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->count())->toBe(1);

    // auto_messages_config 仅进 snapshot_config 不进 compiled_config，变更也应建新版
    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id]), [
            ...$payload,
            'auto_messages_config' => receptionPlanAutoMessagesPayload([
                'ai_welcome' => ['enabled' => true, 'message' => '您好，有什么可以帮您？'],
            ]),
        ])
        ->assertRedirect();

    expect(ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->count())->toBe(2);
});

test('超级管理员可以删除接待方案当没有版本引用时', function () {
    $plan = ReceptionPlan::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->delete(route('admin.manage.reception.plans.destroy', ['plan' => $plan->id]))
        ->assertRedirect(route('admin.manage.reception.plans.index'));

    $this->assertSoftDeleted('reception_plans', ['id' => $plan->id]);
});

test('超级管理员可以查看接待方案回收站并恢复方案', function () {
    $plan = ReceptionPlan::factory()->create([
        'name' => '已删除接待方案',
    ]);
    ReceptionPlanVersion::factory()->create([
        'reception_plan_id' => $plan->id,
        'version_number' => 1,
    ]);
    $plan->delete();

    $this->actingAs($this->user)
        ->get(route('admin.manage.reception.plans.trash', []))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reception/plans/Trash')
            ->has('trashed_plan_list', 1)
            ->where('trashed_plan_list.0.id', (string) $plan->id)
            ->where('trashed_plan_list.0.name', '已删除接待方案')
            ->where('trashed_plan_list_pagination.total', 1)
        );

    $this->actingAs($this->user)
        ->from(route('admin.manage.reception.plans.trash', []))
        ->put(route('admin.manage.reception.plans.restore', ['plan' => $plan->id,
        ]))
        ->assertRedirect(route('admin.manage.reception.plans.trash', []));

    expect(ReceptionPlan::query()->whereKey($plan->id)->exists())->toBeTrue()
        ->and(ReceptionPlan::onlyTrashed()->whereKey($plan->id)->exists())->toBeFalse();
});

test('当 PlanVersion 仍被会话引用时阻止删除', function () {
    $plan = ReceptionPlan::factory()->create([
    ]);
    $version = ReceptionPlanVersion::factory()->create([
        'reception_plan_id' => $plan->id,
        'version_number' => 1,
    ]);
    Conversation::factory()->create([
        'reception_plan_version_id' => $version->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.reception.plans.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.reception.plans.destroy', ['plan' => $plan->id]))
        ->assertRedirect()
        ->assertSessionHasErrors('toast');

    expect(ReceptionPlan::query()->whereKey($plan->id)->exists())->toBeTrue();
});

test('当方案仍被渠道绑定时阻止删除', function () {
    $plan = ReceptionPlan::factory()->create([
    ]);
    Channel::factory()->create([
        'reception_plan_id' => $plan->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.reception.plans.index'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('admin.manage.reception.plans.destroy', ['plan' => $plan->id]))
        ->assertRedirect()
        ->assertSessionHasErrors('toast');

    expect(ReceptionPlan::query()->whereKey($plan->id)->exists())->toBeTrue();
});

test('保存接待方案生成的版本快照含编译后的运行时配置', function () {
    createReceptionTestModel(createReceptionTestProvider());
    $strategyConfig = receptionPlanStrategyPayload([
        'reception_mode' => ReceptionRoutingMode::TeammateFirst->value,
        'unassigned_ai_takeover_enabled' => true,
        'unassigned_ai_takeover_timeout_seconds' => 60,
    ]);

    $plan = ReceptionPlan::factory()->create([
        'name' => '可发布方案',
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id]), [
            'name' => '可发布方案',
            'description' => '发布用方案说明',
            'persona_display_name' => '小 A',
            'persona_tone' => 'friendly',
            'global_instructions' => '保持简洁',
            'service_scenarios' => [],
            'strategy_config' => $strategyConfig,
            'auto_messages_config' => receptionPlanAutoMessagesPayload(),
            'translation_config' => receptionPlanTranslationPayload(),
        ])
        ->assertRedirect();

    $version = ReceptionPlanVersion::query()->where('reception_plan_id', $plan->id)->firstOrFail();

    expect($version->version_number)->toBe(1)
        ->and($version->status)->toBe(ReceptionPlanVersionStatus::Published)
        ->and($version->published_by_user_id)->toBe($this->user->id)
        ->and($version->compiled_config['reception_agent']['instruction'])->toContain('小 A')
        ->and($version->compiled_config['reception_agent']['instruction'])->toContain('保持简洁')
        ->and($version->compiled_config['service_scenarios'])->toBe([])
        ->and($version->snapshot_config['name'])->toBe('可发布方案')
        ->and($version->snapshot_config['strategy_config'])->toBe($strategyConfig);
});

test('有接待方案查看权限的用户可以访问接待方案一级菜单', function () {
    $viewer = User::factory()->create([
        'permissions' => [UserPermission::ReceptionPlansView->value],
    ]);
    $userWithoutPermission = User::factory()->create([
        'permissions' => [],
    ]);

    $this->actingAs($viewer)
        ->get(route('admin.manage.reception.plans.index'))
        ->assertOk();

    $this->actingAs($userWithoutPermission)
        ->get(route('admin.manage.reception.plans.index'))
        ->assertForbidden();
});

test('单租户下超级管理员可以访问任意方案详情', function () {
    $localPlan = ReceptionPlan::factory()->create([]);
    $otherPlan = ReceptionPlan::factory()->create([]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.reception.plans.show', ['plan' => $localPlan->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reception/plans/Detail')
            ->where('plan.id', (string) $localPlan->id)
        );

    $this->actingAs($this->user)
        ->get(route('admin.manage.reception.plans.show', ['plan' => $otherPlan->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reception/plans/Detail')
            ->where('plan.id', (string) $otherPlan->id)
        );

    $this->actingAs($this->user)
        ->delete(route('admin.manage.reception.plans.destroy', ['plan' => $otherPlan->id]))
        ->assertRedirect();

    expect(ReceptionPlan::query()->whereKey($otherPlan->id)->exists())->toBeFalse();
});
