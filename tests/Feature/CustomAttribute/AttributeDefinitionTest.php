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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// === List ===

test('已认证用户可以查看属性定义页面', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    AttributeDefinition::factory()->for($workspace)->create([
        'name' => 'Company',
        'key' => 'company',
    ]);

    $props = ShowAttributeDefinitionListAction::run(workspace: $workspace);

    expect($props->definition_list)->toHaveCount(1)
        ->and($props->definition_list[0]->name)->toBe('Company');
});

test('列表显示属性定义', function () {
    [$workspace] = createWorkspaceWithOwner();

    $definitions = AttributeDefinition::factory()->count(2)->for($workspace)->create();
    $contact = Contact::factory()->for($workspace)->create();
    ContactAttributeValue::factory()->create([
        'workspace_id' => $workspace->id,
        'contact_id' => $contact->id,
        'definition_id' => $definitions->first()->id,
        'value_json' => ['value' => 'Acme'],
    ]);

    $result = ShowAttributeDefinitionListAction::run($workspace);
    $usedDefinition = collect($result->definition_list)->firstWhere('id', $definitions->first()->id);

    expect($result->definition_list)->toHaveCount(2)
        ->and($result->type_options)->toHaveCount(7)
        ->and($usedDefinition)->not->toBeNull()
        ->and($usedDefinition->usage_count)->toBe(1);
});

test('已认证用户可以查看属性定义回收站页面', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $deletedDefinition = AttributeDefinition::factory()->for($workspace)->deleted()->create([
        'name' => 'Deleted Attribute',
        'key' => 'deleted_attribute',
    ]);
    AttributeDefinition::factory()->for($workspace)->create([
        'name' => 'Active Attribute',
    ]);

    $props = ShowAttributeDefinitionTrashAction::run(workspace: $workspace);

    expect($props->trashed_definition_list)->toHaveCount(1)
        ->and($props->trashed_definition_list[0]->id)->toBe((string) $deletedDefinition->id)
        ->and($props->trashed_definition_list[0]->name)->toBe('Deleted Attribute')
        ->and($props->trashed_definition_list_pagination->total)->toBe(1);
});

// === Create ===

test('可以创建文本属性定义', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'company_name',
        name: 'Company Name',
        description: 'The company',
        type: 'text',
        config: null,
    );

    $def = CreateAttributeDefinitionAction::run($workspace, $data);

    expect($def)->toBeInstanceOf(AttributeDefinition::class)
        ->and($def->key)->toBe('company_name')
        ->and($def->type->value)->toBe('text')
        ->and($def->workspace_id)->toBe($workspace->id);
});

test('可以创建single_select定义并包含选项', function () {
    [$workspace] = createWorkspaceWithOwner();

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

    $def = CreateAttributeDefinitionAction::run($workspace, $data);

    expect($def->config['options'])->toHaveCount(2);
});

test('拒绝保留键', function () {
    [$workspace] = createWorkspaceWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'name',
        name: 'Name',
        description: null,
        type: 'text',
        config: null,
    );

    CreateAttributeDefinitionAction::run($workspace, $data);
})->throws(ValidationException::class);

test('拒绝重复键', function () {
    [$workspace] = createWorkspaceWithOwner();

    AttributeDefinition::factory()->for($workspace)->create(['key' => 'existing_key']);

    $data = new FormCreateAttributeDefinitionData(
        key: 'existing_key',
        name: 'Test',
        description: null,
        type: 'text',
        config: null,
    );

    CreateAttributeDefinitionAction::run($workspace, $data);
})->throws(ValidationException::class);

test('拒绝无效键格式', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $this->actingAs($user)
        ->post(route('workspace.manage.attributes.store', ['slug' => $workspace->slug]), [
            'key' => 'Invalid-Key',
            'name' => 'Test',
            'type' => 'text',
        ])
        ->assertSessionHasErrors([
            'key' => __('custom_attribute.invalid_key_format'),
        ]);
});

test('选择类型需要至少一个选项', function () {
    [$workspace] = createWorkspaceWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'level',
        name: 'Level',
        description: null,
        type: 'single_select',
        config: ['options' => []],
    );

    CreateAttributeDefinitionAction::run($workspace, $data);
})->throws(ValidationException::class);

test('拒绝重复选项代码', function () {
    [$workspace] = createWorkspaceWithOwner();

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

    CreateAttributeDefinitionAction::run($workspace, $data);
})->throws(ValidationException::class);

test('拒绝启用筛选用于不支持属性类型', function () {
    [$workspace] = createWorkspaceWithOwner();

    $data = new FormCreateAttributeDefinitionData(
        key: 'company_name',
        name: 'Company Name',
        description: null,
        type: 'text',
        config: null,
        is_filterable: true,
    );

    CreateAttributeDefinitionAction::run($workspace, $data);
})->throws(ValidationException::class);

// === Update ===

test('可以更新名称和描述', function () {
    [$workspace] = createWorkspaceWithOwner();

    $def = AttributeDefinition::factory()->for($workspace)->text()->create();

    $data = new FormUpdateAttributeDefinitionData(
        name: 'Updated Name',
        description: 'Updated desc',
        config: null,
    );

    $updated = UpdateAttributeDefinitionAction::run($workspace, $def->id, $data);

    expect($updated->name)->toBe('Updated Name')
        ->and($updated->description)->toBe('Updated desc');
});

test('不能改变键或类型通过更新', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $def = AttributeDefinition::factory()->for($workspace)->text()->create([
        'key' => 'original_key',
    ]);

    $this->actingAs($user)
        ->put(route('workspace.manage.attributes.update', ['slug' => $workspace->slug, 'id' => $def->id]), [
            'name' => $def->name,
            'config' => null,
        ])
        ->assertSessionDoesntHaveErrors();

    $def->refresh();
    expect($def->key)->toBe('original_key')
        ->and($def->type->value)->toBe('text');
});

test('拒绝删除在使用选项代码', function () {
    [$workspace] = createWorkspaceWithOwner();

    $def = AttributeDefinition::factory()->for($workspace)->singleSelect([
        ['code' => 'vip', 'label' => 'VIP'],
        ['code' => 'normal', 'label' => 'Normal'],
    ])->create();

    $contact = Contact::factory()->for($workspace)->create();
    ContactAttributeValue::factory()->create([
        'workspace_id' => $workspace->id,
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

    UpdateAttributeDefinitionAction::run($workspace, $def->id, $data);
})->throws(ValidationException::class);

test('拒绝启用筛选用于不支持定义类型期间更新', function () {
    [$workspace] = createWorkspaceWithOwner();

    $def = AttributeDefinition::factory()->for($workspace)->text()->create();

    $data = new FormUpdateAttributeDefinitionData(
        name: $def->name,
        description: $def->description,
        config: null,
        is_filterable: true,
    );

    UpdateAttributeDefinitionAction::run($workspace, $def->id, $data);
})->throws(ValidationException::class);

// === Archive & Restore ===

test('可以删除和恢复定义', function () {
    [$workspace] = createWorkspaceWithOwner();

    $def = AttributeDefinition::factory()->for($workspace)->create();

    ArchiveAttributeDefinitionAction::run($workspace, $def->id);
    $def->refresh();
    expect($def->trashed())->toBeTrue();

    RestoreAttributeDefinitionAction::run($workspace, $def->id);
    $def->refresh();
    expect($def->trashed())->toBeFalse();
});

// === Reorder ===

test('可以重排定义', function () {
    [$workspace] = createWorkspaceWithOwner();

    $a = AttributeDefinition::factory()->for($workspace)->create(['display_order' => 0]);
    $b = AttributeDefinition::factory()->for($workspace)->create(['display_order' => 1]);
    $c = AttributeDefinition::factory()->for($workspace)->create(['display_order' => 2]);

    ReorderAttributeDefinitionsAction::run($workspace, [$c->id, $a->id, $b->id]);

    expect($c->fresh()->display_order)->toBe(0)
        ->and($a->fresh()->display_order)->toBe(1)
        ->and($b->fresh()->display_order)->toBe(2);
});

test('重排拒绝不完整定义列表', function () {
    [$workspace] = createWorkspaceWithOwner();

    $a = AttributeDefinition::factory()->for($workspace)->create(['display_order' => 0]);
    $b = AttributeDefinition::factory()->for($workspace)->create(['display_order' => 1]);

    ReorderAttributeDefinitionsAction::run($workspace, [$a->id, $a->id]);
})->throws(ValidationException::class);

test('重排只考虑非已删除定义', function () {
    [$workspace] = createWorkspaceWithOwner();

    $activeA = AttributeDefinition::factory()->for($workspace)->create(['display_order' => 0]);
    $deleted = AttributeDefinition::factory()->for($workspace)->deleted()->create(['display_order' => 1]);
    $activeB = AttributeDefinition::factory()->for($workspace)->create(['display_order' => 2]);

    ReorderAttributeDefinitionsAction::run($workspace, [$activeB->id, $activeA->id]);

    expect($activeB->fresh()->display_order)->toBe(0)
        ->and($activeA->fresh()->display_order)->toBe(1)
        ->and($deleted->fresh()->display_order)->toBe(1);
});

test('恢复追加定义到末尾的活跃列表', function () {
    [$workspace] = createWorkspaceWithOwner();

    AttributeDefinition::factory()->for($workspace)->create(['display_order' => 0]);
    AttributeDefinition::factory()->for($workspace)->create(['display_order' => 1]);
    $deleted = AttributeDefinition::factory()->for($workspace)->deleted()->create(['display_order' => 0]);

    RestoreAttributeDefinitionAction::run($workspace, $deleted->id);

    expect($deleted->fresh()->display_order)->toBe(2)
        ->and($deleted->fresh()->trashed())->toBeFalse();
});

// === Cross-workspace isolation ===

test('不能访问定义来自另一个工作区', function () {
    [$workspace1] = createWorkspaceWithOwner();
    [$workspace2] = createWorkspaceWithOwner();

    $def = AttributeDefinition::factory()->for($workspace1)->create();

    expect(fn () => ArchiveAttributeDefinitionAction::run($workspace2, $def->id))
        ->toThrow(ModelNotFoundException::class);
});
