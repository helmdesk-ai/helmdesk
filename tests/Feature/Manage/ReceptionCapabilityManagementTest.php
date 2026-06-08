<?php

use App\Actions\Reception\Plan\CompileReceptionPlanAction;
use App\Enums\AiModelPurpose;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Models\ReceptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = $this->createUserWithSystem();
});

function createCapabilityTestProvider(array $attributes = []): AiProvider
{
    return makeUsableAiProvider($attributes);
}

/**
 * Seed 一个全局接待对话 LLM 模型（模型已全局化，运行时按用途取用）。
 *
 * @param  array<string, mixed>  $attributes
 */
function createCapabilityTestModel(AiProvider $provider, array $attributes = []): AiModel
{
    return makeAiModel(AiModelPurpose::ReceptionChat, $provider);
}

function createCapabilityTestPlan(array $attributes = []): ReceptionPlan
{
    // 模型已全局化：seed 一个全局可用模型，方案本身不再存模型。
    createCapabilityTestModel(createCapabilityTestProvider());

    return ReceptionPlan::factory()->create($attributes);
}

/**
 * @param  list<array<string, mixed>>  $serviceScenarios
 * @param  list<string>  $knowledgeBaseIds
 * @param  list<string>  $mcpToolIds
 * @return array<string, mixed>
 */
function receptionPlanUpdatePayload(
    ReceptionPlan $plan,
    array $serviceScenarios,
    array $knowledgeBaseIds = [],
    array $mcpToolIds = [],
): array {
    $persona = is_array($plan->persona_config) ? $plan->persona_config : [];

    // 模型已全局化：更新方案不再提交模型字段，只提交人设 / 服务场景 / KB / MCP / 策略等配置。
    return [
        'name' => $plan->name,
        'description' => $plan->description,
        'persona_display_name' => $persona['display_name'],
        'persona_tone' => $persona['tone'],
        'global_instructions' => $plan->global_instructions,
        'service_scenarios' => $serviceScenarios,
        'knowledge_base_ids' => $knowledgeBaseIds,
        'mcp_tool_ids' => $mcpToolIds,
        'strategy_config' => $plan->strategy_config,
        'auto_messages_config' => $plan->auto_messages_config,
    ];
}

test('超级管理员可以打开接待方案详情页并看到服务场景模板', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.reception.plans.show', ['plan' => $plan->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reception/plans/Detail')
            ->has('service_scenario_templates', 4)
            ->where('service_scenario_templates.0.code', 'order_query')
            ->where('service_scenario_templates.0.name', '订单查询')
            ->where('service_scenario_templates.1.code', 'faq')
            ->where('service_scenario_templates.2.code', 'aftersale')
            ->where('service_scenario_templates.3.code', 'logistics')
        );
});

test('通过更新方案草稿可写入服务场景 JSON', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload($plan, [[
            'name' => '订单查询',
            'description' => '处理订单状态、订单详情、订单列表类问题。',
            'instructions' => '你是订单查询专员，按订单号回答访客。',
        ]]))
        ->assertRedirect(route('admin.manage.reception.plans.show', ['plan' => $plan->id,
        ]));

    $plan->refresh();
    expect($plan->capabilities)->toHaveCount(1)
        ->and($plan->capabilities[0]['name'])->toBe('订单查询')
        ->and($plan->capabilities[0]['instructions'])->toBe('你是订单查询专员，按订单号回答访客。');
});

test('服务场景名称可直接使用中文', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload($plan, [[
            'name' => '订单查询',
            'description' => '',
            'instructions' => '指令',
        ]]))
        ->assertRedirect();

    expect($plan->fresh()->capabilities)
        ->toHaveCount(1)
        ->and($plan->fresh()->capabilities[0]['name'])
        ->toBe('订单查询');
});

test('同一方案内重复服务场景名称会被拒绝', function () {
    $plan = createCapabilityTestPlan([
        'capabilities' => [[
            'name' => '订单查询',
            'description' => '',
            'instructions' => '原始指令',
        ]],
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload($plan, [
            ['name' => '订单查询', 'description' => '', 'instructions' => '原始指令'],
            ['name' => '订单查询', 'description' => '', 'instructions' => '不同指令'],
        ]))
        ->assertSessionHasErrors(['service_scenarios.1.name']);

    $plan->refresh();
    expect($plan->capabilities)->toHaveCount(1);
});

test('带空格或大小写差异的服务场景名称也按重复处理', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload($plan, [
            ['name' => 'Order Lookup', 'description' => '', 'instructions' => '指令'],
            ['name' => 'order lookup ', 'description' => '', 'instructions' => '指令'],
        ]))
        ->assertSessionHasErrors(['service_scenarios.1.name']);

    expect($plan->fresh()->capabilities)->toBe([]);
});

test('单租户下方案可以引用任意知识库', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);
    $knowledgeBase = KnowledgeBase::factory()->create([
        'name' => '外部知识库',
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload(
            $plan,
            [['name' => '常见问题', 'description' => '', 'instructions' => '指令']],
            [$knowledgeBase->id],
        ))
        ->assertRedirect();

    expect($plan->fresh()->knowledge_base_ids)->toBe([$knowledgeBase->id]);
});

test('超级管理员可以更新服务场景', function () {
    $plan = createCapabilityTestPlan([
        'capabilities' => [[
            'name' => '订单查询',
            'description' => '旧描述',
            'instructions' => '旧指令',
        ]],
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload($plan, [[
            'name' => '订单查询（新）',
            'description' => '新描述',
            'instructions' => '新指令',
        ]]))
        ->assertRedirect();

    $plan->refresh();
    expect($plan->capabilities)->toHaveCount(1)
        ->and($plan->capabilities[0]['name'])->toBe('订单查询（新）')
        ->and($plan->capabilities[0]['instructions'])->toBe('新指令');
});

test('更新方案草稿时可移除单个服务场景', function () {
    $plan = createCapabilityTestPlan([
        'capabilities' => [
            ['name' => '订单查询', 'description' => '', 'instructions' => ''],
            ['name' => '常见问题', 'description' => '', 'instructions' => ''],
        ],
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload($plan, [
            ['name' => '常见问题', 'description' => '', 'instructions' => '常见问题助手'],
        ]))
        ->assertRedirect();

    $plan->refresh();
    expect($plan->capabilities)->toHaveCount(1)
        ->and($plan->capabilities[0]['name'])->toBe('常见问题');
});

test('单租户下超级管理员可以更新任意方案', function () {
    $foreignProvider = createCapabilityTestProvider(['slug' => 'foreign-provider']);
    $foreignModel = createCapabilityTestModel($foreignProvider);
    $foreignPlan = ReceptionPlan::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $foreignPlan->id,
        ]), receptionPlanUpdatePayload($foreignPlan, []))
        ->assertRedirect();
});

test('编译方案时服务场景写入 compiled_config，方案级 KB 作为快照存储', function () {
    $provider = createCapabilityTestProvider();
    $model = createCapabilityTestModel($provider);
    $kb = KnowledgeBase::factory()->create([
        'name' => '商品资料库',
        'category' => 'standard',
    ]);

    $plan = ReceptionPlan::factory()->create([
        'name' => '含服务场景方案',
        'knowledge_base_ids' => [$kb->id],
        'capabilities' => [
            ['name' => '订单查询', 'description' => '订单类问题', 'instructions' => '你是订单查询专员'],
            ['name' => '常见问题', 'description' => '', 'instructions' => '基于知识库回答'],
        ],
    ]);

    $compiled = app(CompileReceptionPlanAction::class)->handle($plan);

    expect($compiled['compiled_config']['service_scenarios'])->toHaveCount(2)
        ->and($compiled['compiled_config']['service_scenarios'][0]['name'])->toBe('订单查询')
        ->and($compiled['compiled_config']['service_scenarios'][0]['description'])->toBe('订单类问题')
        ->and($compiled['compiled_config']['service_scenarios'][0]['instructions'])->toBe('你是订单查询专员')
        ->and($compiled['compiled_config']['service_scenarios'][1]['name'])->toBe('常见问题')
        ->and($compiled['compiled_config']['service_scenarios'][1]['description'])->toBe('');

    expect($compiled['compiled_config']['knowledge_bases'])->toHaveCount(1)
        ->and($compiled['compiled_config']['knowledge_bases'][0]['id'])->toBe($kb->id)
        ->and($compiled['compiled_config']['knowledge_bases'][0]['name'])->toBe('商品资料库')
        ->and($compiled['compiled_config']['knowledge_bases'][0]['category'])->toBe('standard');

    expect($compiled['compiled_config']['reception_agent']['instruction'])->toContain('订单查询')
        ->and($compiled['compiled_config']['reception_agent']['instruction'])->toContain('常见问题')
        ->and($compiled['compiled_config']['reception_agent']['instruction'])->not->toContain('order_query')
        ->and($compiled['compiled_config']['reception_agent']['instruction'])->not->toContain('faq');
});

test('保存方案时可写入方案级 MCP 工具引用', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);
    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->for($server, 'server')->create(['name' => 'lookup_order']);

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload(
            $plan,
            [['name' => '订单查询', 'description' => '', 'instructions' => '指令']],
            [],
            [$tool->id],
        ))
        ->assertRedirect();

    $plan->refresh();
    expect($plan->always_on_tools)->toBe([$tool->id]);
});

test('保存方案时拒绝不可用 MCP 工具引用', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);
    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->removed()->for($server, 'server')->create();

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload(
            $plan,
            [['name' => '订单查询', 'description' => '', 'instructions' => '指令']],
            [],
            [$tool->id],
        ))
        ->assertSessionHasErrors(['mcp_tool_ids']);

    expect($plan->fresh()->always_on_tools)->toBe([]);
});

test('单租户下方案可以引用任意可用 MCP 工具', function () {
    $plan = createCapabilityTestPlan(['capabilities' => []]);
    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->for($server, 'server')->create();

    $this->actingAs($this->user)
        ->put(route('admin.manage.reception.plans.update', ['plan' => $plan->id,
        ]), receptionPlanUpdatePayload(
            $plan,
            [['name' => '订单查询', 'description' => '', 'instructions' => '指令']],
            [],
            [$tool->id],
        ))
        ->assertRedirect();

    expect($plan->fresh()->always_on_tools)->toBe([$tool->id]);
});

test('编译方案时方案级 MCP 工具写入 compiled_config 快照', function () {
    $provider = createCapabilityTestProvider();
    $model = createCapabilityTestModel($provider);
    $server = McpServer::factory()
        ->create(['slug' => 'orders-mcp', 'name' => '订单 MCP']);
    $tool = McpTool::factory()->for($server, 'server')->create([
        'name' => 'lookup_order',
        'description' => '按订单号查询',
    ]);

    $plan = ReceptionPlan::factory()->create([
        'name' => '含工具方案',
        'always_on_tools' => [$tool->id],
        'capabilities' => [
            ['name' => '订单查询', 'description' => '订单类问题', 'instructions' => '使用 lookup_order 工具'],
        ],
    ]);

    $compiled = app(CompileReceptionPlanAction::class)->handle($plan);

    expect($compiled['compiled_config']['mcp_tools'])->toHaveCount(1)
        ->and($compiled['compiled_config']['mcp_tools'][0]['id'])->toBe($tool->id)
        ->and($compiled['compiled_config']['mcp_tools'][0]['name'])->toBe('lookup_order')
        ->and($compiled['compiled_config']['mcp_tools'][0]['description'])->toBe('按订单号查询')
        ->and($compiled['compiled_config']['mcp_tools'][0]['server_id'])->toBe($server->id)
        ->and($compiled['compiled_config']['mcp_tools'][0]['server_slug'])->toBe('orders-mcp')
        ->and($compiled['compiled_config']['mcp_tools'][0]['server_name'])->toBe('订单 MCP');
});

test('编译时方案级引用悬空 MCP 工具会抛 BusinessException', function () {
    $provider = createCapabilityTestProvider();
    $model = createCapabilityTestModel($provider);

    $plan = ReceptionPlan::factory()->create([
        'always_on_tools' => ['01H00000000000000000000000'],
        'capabilities' => [
            ['name' => '订单查询', 'description' => '', 'instructions' => ''],
        ],
    ]);

    expect(fn () => app(CompileReceptionPlanAction::class)->handle($plan))
        ->toThrow(BusinessException::class);
});

test('编译时方案级引用不可用 MCP 工具会抛 BusinessException', function () {
    $provider = createCapabilityTestProvider();
    $model = createCapabilityTestModel($provider);
    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->removed()->for($server, 'server')->create();

    $plan = ReceptionPlan::factory()->create([
        'always_on_tools' => [$tool->id],
        'capabilities' => [
            ['name' => '订单查询', 'description' => '', 'instructions' => ''],
        ],
    ]);

    expect(fn () => app(CompileReceptionPlanAction::class)->handle($plan))
        ->toThrow(BusinessException::class);
});

test('编译时方案级引用任意可用 MCP 工具会写入快照', function () {
    $provider = createCapabilityTestProvider();
    $model = createCapabilityTestModel($provider);
    $server = McpServer::factory()->create();
    $tool = McpTool::factory()->for($server, 'server')->create();

    $plan = ReceptionPlan::factory()->create([
        'always_on_tools' => [$tool->id],
        'capabilities' => [
            ['name' => '订单查询', 'description' => '', 'instructions' => ''],
        ],
    ]);

    $compiled = app(CompileReceptionPlanAction::class)->handle($plan);

    expect($compiled['compiled_config']['mcp_tools'])->toHaveCount(1);
});

test('编译时方案级引用悬空知识库会抛 BusinessException', function () {
    $provider = createCapabilityTestProvider();
    $model = createCapabilityTestModel($provider);

    $plan = ReceptionPlan::factory()->create([
        'knowledge_base_ids' => ['01H00000000000000000000000'],
        'capabilities' => [
            ['name' => '常见问题', 'description' => '', 'instructions' => ''],
        ],
    ]);

    expect(fn () => app(CompileReceptionPlanAction::class)->handle($plan))
        ->toThrow(BusinessException::class);
});
