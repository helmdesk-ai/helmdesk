<?php

use App\Actions\AiModel\CreateAiModelAction;
use App\Actions\AiModel\DeleteAiModelAction;
use App\Actions\AiModel\ReorderAiModelsAction;
use App\Actions\AiModel\ShowAiModelListAction;
use App\Actions\AiModel\ShowCreateAiModelPageAction;
use App\Actions\AiModel\ToggleAiModelAction;
use App\Actions\AiModel\UpdateAiModelAction;
use App\Data\AiModel\FormCreateAiModelData;
use App\Data\AiModel\FormUpdateAiModelData;
use App\Enums\AiModelPurpose;
use App\Enums\AiModelType;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
});

/**
 * 构造一条创建模型表单数据（一行=一个模型+一个用途）。
 *
 * @param  array<string, mixed>  $overrides
 */
function createModelData(string $providerId, array $overrides = []): FormCreateAiModelData
{
    return FormCreateAiModelData::from([
        'ai_provider_id' => $providerId,
        'purpose' => AiModelPurpose::ReceptionChat->value,
        'model_id' => 'gpt-x',
        'name' => 'GPT-X',
        ...$overrides,
    ]);
}

test('创建模型按用途的能力类型派生 type 并排在该用途末尾', function () {
    $provider = makeUsableAiProvider();

    $first = app(CreateAiModelAction::class)->handle(createModelData($provider->id, ['model_id' => 'a']));
    $second = app(CreateAiModelAction::class)->handle(createModelData($provider->id, ['model_id' => 'b']));

    expect($first->purpose)->toBe(AiModelPurpose::ReceptionChat)
        ->and($first->type)->toBe(AiModelType::Llm->value)
        ->and($first->sort_order)->toBe(0)
        ->and($second->sort_order)->toBe(1);
});

test('创建 rerank 用途模型 type 派生为 rerank', function () {
    $provider = makeUsableAiProvider();

    $model = app(CreateAiModelAction::class)->handle(createModelData($provider->id, [
        'purpose' => AiModelPurpose::Rerank->value,
    ]));

    expect($model->purpose)->toBe(AiModelPurpose::Rerank)
        ->and($model->type)->toBe(AiModelType::Rerank->value);
});

test('创建 embedding 用途模型 type 派生为 embedding', function () {
    $provider = makeUsableAiProvider();

    $model = app(CreateAiModelAction::class)->handle(createModelData($provider->id, [
        'purpose' => AiModelPurpose::Embedding->value,
    ]));

    expect($model->purpose)->toBe(AiModelPurpose::Embedding)
        ->and($model->type)->toBe(AiModelType::Embedding->value);
});

test('同供应商下相同 model_id 与用途重复创建被拒', function () {
    $provider = makeUsableAiProvider();

    app(CreateAiModelAction::class)->handle(createModelData($provider->id));

    expect(fn () => app(CreateAiModelAction::class)->handle(createModelData($provider->id)))
        ->toThrow(BusinessException::class);
});

test('同 model_id 不同用途可分别创建', function () {
    $provider = makeUsableAiProvider();

    app(CreateAiModelAction::class)->handle(createModelData($provider->id));
    $second = app(CreateAiModelAction::class)->handle(createModelData($provider->id, [
        'purpose' => AiModelPurpose::Assistant->value,
    ]));

    expect($second->purpose)->toBe(AiModelPurpose::Assistant)
        ->and(AiModel::query()->where('model_id', 'gpt-x')->count())->toBe(2);
});

test('更新模型可改名称与启用状态', function () {
    $model = makeAiModel(AiModelPurpose::ReceptionChat);

    app(UpdateAiModelAction::class)->handle($model->id, FormUpdateAiModelData::from([
        'name' => '改名后',
        'is_active' => false,
    ]));

    $fresh = $model->fresh();
    expect($fresh->name)->toBe('改名后')
        ->and($fresh->is_active)->toBeFalse();
});

test('reorder 按提交顺序写回同用途的 sort_order', function () {
    $a = makeAiModel();
    $b = makeAiModel();
    $c = makeAiModel();

    app(ReorderAiModelsAction::class)->handle(AiModelPurpose::ReceptionChat, [$c->id, $a->id, $b->id]);

    expect($c->fresh()->sort_order)->toBe(0)
        ->and($a->fresh()->sort_order)->toBe(1)
        ->and($b->fresh()->sort_order)->toBe(2);
});

test('reorder 提交集合与该用途现有模型不一致时抛校验异常', function () {
    $a = makeAiModel();
    makeAiModel();

    expect(fn () => app(ReorderAiModelsAction::class)->handle(AiModelPurpose::ReceptionChat, [$a->id]))
        ->toThrow(ValidationException::class);
});

test('切换模型启用状态', function () {
    $model = makeAiModel();

    app(ToggleAiModelAction::class)->handle($model->id);

    expect($model->fresh()->is_active)->toBeFalse();
});

test('删除模型', function () {
    $model = makeAiModel();

    app(DeleteAiModelAction::class)->handle($model->id);

    expect(AiModel::query()->find($model->id))->toBeNull();
});

test('列表页下发全量模型与用途 Tab', function () {
    $provider = makeUsableAiProvider();
    makeAiModel(AiModelPurpose::ReceptionChat, $provider);

    $props = ShowAiModelListAction::run();

    expect($props->models)->toHaveCount(1)
        ->and($props->purpose_tabs)->toHaveCount(count(AiModelPurpose::cases()));
});

test('新增模型页下发供应商选项、品牌目录与全部用途选项', function () {
    makeUsableAiProvider();

    $props = ShowCreateAiModelPageAction::run();

    expect($props->provider_options)->not->toBeEmpty()
        ->and($props->default_models_by_brand)->not->toBeEmpty()
        ->and($props->purpose_options)->toHaveCount(count(AiModelPurpose::cases()));
});

test('store 端点创建模型', function () {
    $provider = makeUsableAiProvider();

    $this->actingAs($this->user)
        ->post(route('admin.manage.ai.models.store'), [
            'ai_provider_id' => $provider->id,
            'purpose' => AiModelPurpose::ReceptionChat->value,
            'model_id' => 'gpt-endpoint',
            'name' => '端点模型',
        ])
        ->assertRedirect();

    expect(AiModel::query()->where('model_id', 'gpt-endpoint')->exists())->toBeTrue();
});

test('list 端点渲染模型管理页', function () {
    makeAiModel();

    $this->actingAs($this->user)
        ->get(route('admin.manage.ai.models.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('systemSettings/aiModels/List')
            ->has('models', 1)
            ->has('purpose_tabs'));
});

test('无系统设置查看权限的用户不能访问 AI 模型管理页', function () {
    $userWithoutPermission = User::factory()->create(['permissions' => []]);

    $this->actingAs($userWithoutPermission)
        ->get(route('admin.manage.ai.models.index'))
        ->assertForbidden();
});
