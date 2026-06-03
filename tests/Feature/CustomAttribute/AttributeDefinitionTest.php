<?php

use App\Actions\CustomAttribute\ArchiveAttributeDefinitionAction;
use App\Actions\CustomAttribute\CreateAttributeDefinitionAction;
use App\Actions\CustomAttribute\ReorderAttributeDefinitionsAction;
use App\Actions\CustomAttribute\RestoreAttributeDefinitionAction;
use App\Actions\CustomAttribute\ShowAttributeDefinitionListAction;
use App\Actions\CustomAttribute\ShowAttributeDefinitionTrashAction;
use App\Actions\CustomAttribute\UpdateAttributeDefinitionAction;
use App\Data\CustomAttribute\FormCreateAttributeDefinitionData;
use App\Data\CustomAttribute\FormUpdateAttributeDefinitionData;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Models\ContactAttributeValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// === List ===

test('已认证用户可以查看属性定义页面', function () {
    [$systemContext, $user] = createSystemWithOwner();

    AttributeDefinition::factory()->create([
        'name' => 'Company',
        'key' => 'company',
    ]);

    $props = ShowAttributeDefinitionListAction::run();

    expect($props->definition_list)->toHaveCount(1)
        ->and($props->definition_list[0]->name)->toBe('Company');
});

test('列表显示属性定义', function () {
    [$systemContext] = createSystemWithOwner();

    $definitions = AttributeDefinition::factory()->count(2)->create();
    $contact = Contact::factory()->create();
    ContactAttributeValue::factory()->create([
        'contact_id' => $contact->id,
        'definition_id' => $definitions->first()->id,
        'value_json' => ['value' => 'Acme'],
    ]);

    $result = ShowAttributeDefinitionListAction::run();
    $usedDefinition = collect($result->definition_list)->firstWhere('id', $definitions->first()->id);

    expect($result->definition_list)->toHaveCount(2)
        ->and($result->type_options)->toHaveCount(7)
        ->and($usedDefinition)->not->toBeNull()
        ->and($usedDefinition->usage_count)->toBe(1);
});

test('已认证用户可以查看属性定义回收站页面', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $deletedDefinition = AttributeDefinition::factory()->deleted()->create([
        'name' => 'Deleted Attribute',
        'key' => 'deleted_attribute',
    ]);
    AttributeDefinition::factory()->create([
        'name' => 'Active Attribute',
    ]);

    $props = ShowAttributeDefinitionTrashAction::run();

    expect($props->trashed_definition_list)->toHaveCount(1)
        ->and($props->trashed_definition_list[0]->id)->toBe((string) $deletedDefinition->id)
        ->and($props->trashed_definition_list[0]->name)->toBe('Deleted Attribute')
        ->and($props->trashed_definition_list_pagination->total)->toBe(1);
});

// === Create ===

test('可以创建文本属性定义', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'company_name',
        name: 'Company Name',
        description: 'The company',
        type: 'text',
        config: null,
    );

    $def = CreateAttributeDefinitionAction::run($data);

    expect($def)->toBeInstanceOf(AttributeDefinition::class)
        ->and($def->key)->toBe('company_name')
        ->and($def->type->value)->toBe('text');
});

test('可以创建single_select定义并包含选项', function () {
    [$systemContext] = createSystemWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'customer_level',
        name: 'Customer Level',
        description: null,
        type: 'single_select',
        config: ['options' => [
            ['code' => 'vip', 'label' => 'VIP'],
            ['code' => 'normal', 'label' => 'Normal'],
        ]],
    );

    $def = CreateAttributeDefinitionAction::run($data);

    expect($def->config['options'])->toHaveCount(2);
});

test('拒绝保留键', function () {
    [$systemContext] = createSystemWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'name',
        name: 'Name',
        description: null,
        type: 'text',
        config: null,
    );

    CreateAttributeDefinitionAction::run($data);
})->throws(ValidationException::class);

test('拒绝重复键', function () {
    [$systemContext] = createSystemWithOwner();

    AttributeDefinition::factory()->create(['key' => 'existing_key']);

    $data = new FormCreateAttributeDefinitionData(
        key: 'existing_key',
        name: 'Test',
        description: null,
        type: 'text',
        config: null,
    );

    CreateAttributeDefinitionAction::run($data);
})->throws(ValidationException::class);

test('拒绝无效键格式', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $this->actingAs($user)
        ->post(route('admin.manage.attributes.store'), [
            'key' => 'Invalid-Key',
            'name' => 'Test',
            'type' => 'text',
        ])
        ->assertSessionHasErrors([
            'key' => __('custom_attribute.invalid_key_format'),
        ]);
});

test('选择类型需要至少一个选项', function () {
    [$systemContext] = createSystemWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'level',
        name: 'Level',
        description: null,
        type: 'single_select',
        config: ['options' => []],
    );

    CreateAttributeDefinitionAction::run($data);
})->throws(ValidationException::class);

test('拒绝重复选项代码', function () {
    [$systemContext] = createSystemWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'level',
        name: 'Level',
        description: null,
        type: 'single_select',
        config: ['options' => [
            ['code' => 'a', 'label' => 'A'],
            ['code' => 'a', 'label' => 'B'],
        ]],
    );

    CreateAttributeDefinitionAction::run($data);
})->throws(ValidationException::class);

test('拒绝启用筛选用于不支持属性类型', function () {
    [$systemContext] = createSystemWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'company_name',
        name: 'Company Name',
        description: null,
        type: 'text',
        config: null,
        is_filterable: true,
    );

    CreateAttributeDefinitionAction::run($data);
})->throws(ValidationException::class);

// === Update ===

test('可以更新名称和描述', function () {
    [$systemContext] = createSystemWithOwner();

    $def = AttributeDefinition::factory()->text()->create();

    $data = new FormUpdateAttributeDefinitionData(
        name: 'Updated Name',
        description: 'Updated desc',
        config: null,
    );

    $updated = UpdateAttributeDefinitionAction::run($def->id, $data);

    expect($updated->name)->toBe('Updated Name')
        ->and($updated->description)->toBe('Updated desc');
});

test('不能改变键或类型通过更新', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $def = AttributeDefinition::factory()->text()->create([
        'key' => 'original_key',
    ]);

    $this->actingAs($user)
        ->put(route('admin.manage.attributes.update', ['id' => $def->id]), [
            'name' => $def->name,
            'config' => null,
        ])
        ->assertSessionDoesntHaveErrors();

    $def->refresh();
    expect($def->key)->toBe('original_key')
        ->and($def->type->value)->toBe('text');
});

test('拒绝删除在使用选项代码', function () {
    [$systemContext] = createSystemWithOwner();

    $def = AttributeDefinition::factory()->singleSelect([
        ['code' => 'vip', 'label' => 'VIP'],
        ['code' => 'normal', 'label' => 'Normal'],
    ])->create();

    $contact = Contact::factory()->create();
    ContactAttributeValue::factory()->create([
        'contact_id' => $contact->id,
        'definition_id' => $def->id,
        'value_json' => ['value' => 'vip'],
    ]);

    $data = new FormUpdateAttributeDefinitionData(
        name: $def->name,
        description: null,
        config: ['options' => [
            ['code' => 'normal', 'label' => 'Normal'],
        ]],
    );

    UpdateAttributeDefinitionAction::run($def->id, $data);
})->throws(ValidationException::class);

test('拒绝启用筛选用于不支持定义类型期间更新', function () {
    [$systemContext] = createSystemWithOwner();

    $def = AttributeDefinition::factory()->text()->create();

    $data = new FormUpdateAttributeDefinitionData(
        name: $def->name,
        description: $def->description,
        config: null,
        is_filterable: true,
    );

    UpdateAttributeDefinitionAction::run($def->id, $data);
})->throws(ValidationException::class);

// === Archive & Restore ===

test('可以删除和恢复定义', function () {
    [$systemContext] = createSystemWithOwner();

    $def = AttributeDefinition::factory()->create();

    ArchiveAttributeDefinitionAction::run($def->id);
    $def->refresh();
    expect($def->trashed())->toBeTrue();

    RestoreAttributeDefinitionAction::run($def->id);
    $def->refresh();
    expect($def->trashed())->toBeFalse();
});

// === Reorder ===

test('可以重排定义', function () {
    [$systemContext] = createSystemWithOwner();

    $a = AttributeDefinition::factory()->create(['display_order' => 0]);
    $b = AttributeDefinition::factory()->create(['display_order' => 1]);
    $c = AttributeDefinition::factory()->create(['display_order' => 2]);

    ReorderAttributeDefinitionsAction::run([$c->id, $a->id, $b->id]);

    expect($c->fresh()->display_order)->toBe(0)
        ->and($a->fresh()->display_order)->toBe(1)
        ->and($b->fresh()->display_order)->toBe(2);
});

test('重排拒绝不完整定义列表', function () {
    [$systemContext] = createSystemWithOwner();

    $a = AttributeDefinition::factory()->create(['display_order' => 0]);
    $b = AttributeDefinition::factory()->create(['display_order' => 1]);

    ReorderAttributeDefinitionsAction::run([$a->id, $a->id]);
})->throws(ValidationException::class);

test('重排只考虑非已删除定义', function () {
    [$systemContext] = createSystemWithOwner();

    $activeA = AttributeDefinition::factory()->create(['display_order' => 0]);
    $deleted = AttributeDefinition::factory()->deleted()->create(['display_order' => 1]);
    $activeB = AttributeDefinition::factory()->create(['display_order' => 2]);

    ReorderAttributeDefinitionsAction::run([$activeB->id, $activeA->id]);

    expect($activeB->fresh()->display_order)->toBe(0)
        ->and($activeA->fresh()->display_order)->toBe(1)
        ->and($deleted->fresh()->display_order)->toBe(1);
});

test('恢复追加定义到末尾的活跃列表', function () {
    [$systemContext] = createSystemWithOwner();

    AttributeDefinition::factory()->create(['display_order' => 0]);
    AttributeDefinition::factory()->create(['display_order' => 1]);
    $deleted = AttributeDefinition::factory()->deleted()->create(['display_order' => 0]);

    RestoreAttributeDefinitionAction::run($deleted->id);

    expect($deleted->fresh()->display_order)->toBe(2)
        ->and($deleted->fresh()->trashed())->toBeFalse();
});

test('单租户下按定义 ID 归档自定义属性', function () {
    [$systemContext] = createSystemWithOwner();

    $def = AttributeDefinition::factory()->create();

    ArchiveAttributeDefinitionAction::run($def->id);

    expect($def->fresh()->trashed())->toBeTrue();
});
