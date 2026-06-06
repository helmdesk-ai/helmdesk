<?php

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
    $this->kb = KnowledgeBase::factory()->create([
        'name' => '产品知识库',
    ]);
});

/**
 * 在当前系统下方便地创建一个分组。
 */
function createKnowledgeGroupTestNode(array $attributes = []): KnowledgeGroup
{
    /** @var KnowledgeBase $kb */
    $kb = test()->kb;

    return KnowledgeGroup::query()->create(array_merge([
        'knowledge_base_id' => $kb->id,
        'parent_id' => null,
        'name' => 'Group '.uniqid('', false),
        'sort_order' => 0,
    ], $attributes));
}

test('超级管理员可以创建顶级分组并自动落到同级末尾', function () {
    createKnowledgeGroupTestNode(['name' => '已存在', 'sort_order' => 5]);

    $this->actingAs($this->user)
        ->post(
            route('admin.manage.knowledge-bases.groups.store', ['knowledgeBase' => $this->kb->id,
            ]),
            ['name' => '使用手册', 'parent_id' => '']
        )
        ->assertRedirect();

    $created = KnowledgeGroup::query()
        ->where('knowledge_base_id', $this->kb->id)
        ->where('name', '使用手册')
        ->firstOrFail();

    expect($created->parent_id)->toBeNull()
        ->and($created->sort_order)->toBe(6);
});

test('超级管理员可以创建二级分组（受 2 级限制）', function () {
    $top = createKnowledgeGroupTestNode(['name' => '使用手册']);

    $this->actingAs($this->user)
        ->post(
            route('admin.manage.knowledge-bases.groups.store', ['knowledgeBase' => $this->kb->id,
            ]),
            ['name' => '快速入门', 'parent_id' => $top->id]
        )
        ->assertRedirect();

    expect(
        KnowledgeGroup::query()
            ->where('parent_id', $top->id)
            ->where('name', '快速入门')
            ->exists()
    )->toBeTrue();
});

test('不能基于二级分组再创建分组', function () {
    $top = createKnowledgeGroupTestNode(['name' => '使用手册']);
    $child = createKnowledgeGroupTestNode(['name' => '快速入门', 'parent_id' => $top->id]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->post(
            route('admin.manage.knowledge-bases.groups.store', ['knowledgeBase' => $this->kb->id,
            ]),
            ['name' => '更深一级', 'parent_id' => $child->id]
        )
        ->assertSessionHasErrors(['parent_id']);
});

test('默认分组不能创建子分组', function () {
    $defaultGroup = $this->kb->defaultDocumentGroup()->firstOrFail();

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->post(
            route('admin.manage.knowledge-bases.groups.store', ['knowledgeBase' => $this->kb->id,
            ]),
            ['name' => '默认子分组', 'parent_id' => $defaultGroup->id]
        )
        ->assertSessionHasErrors(['parent_id']);
});

test('同一上级下分组名必须唯一', function () {
    createKnowledgeGroupTestNode(['name' => '重名']);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->post(
            route('admin.manage.knowledge-bases.groups.store', ['knowledgeBase' => $this->kb->id,
            ]),
            ['name' => '重名', 'parent_id' => '']
        )
        ->assertSessionHasErrors(['name']);
});

test('编辑分组可以改名同时改挂上级', function () {
    $oldParent = createKnowledgeGroupTestNode(['name' => '老组']);
    $newParent = createKnowledgeGroupTestNode(['name' => '新组']);
    $child = createKnowledgeGroupTestNode([
        'name' => '快速入门',
        'parent_id' => $oldParent->id,
        'sort_order' => 0,
    ]);
    createKnowledgeGroupTestNode([
        'name' => '已有同级',
        'parent_id' => $newParent->id,
        'sort_order' => 7,
    ]);

    $this->actingAs($this->user)
        ->put(
            route('admin.manage.knowledge-bases.groups.update', ['knowledgeBase' => $this->kb->id,
                'group' => $child->id,
            ]),
            ['name' => '快速上手', 'parent_id' => $newParent->id]
        )
        ->assertRedirect();

    $child->refresh();

    expect($child->name)->toBe('快速上手')
        ->and($child->parent_id)->toBe($newParent->id)
        ->and($child->sort_order)->toBe(8);
});

test('编辑分组可以拉回顶级', function () {
    $top = createKnowledgeGroupTestNode(['name' => '使用手册', 'sort_order' => 1]);
    $child = createKnowledgeGroupTestNode([
        'name' => '快速入门',
        'parent_id' => $top->id,
    ]);

    $this->actingAs($this->user)
        ->put(
            route('admin.manage.knowledge-bases.groups.update', ['knowledgeBase' => $this->kb->id,
                'group' => $child->id,
            ]),
            ['name' => '快速入门', 'parent_id' => '']
        )
        ->assertRedirect();

    $child->refresh();

    expect($child->parent_id)->toBeNull()
        ->and($child->sort_order)->toBe(2);
});

test('包含子分组的分组不能被挂到其它分组下', function () {
    $top = createKnowledgeGroupTestNode(['name' => '使用手册']);
    $other = createKnowledgeGroupTestNode(['name' => '其它顶级']);
    createKnowledgeGroupTestNode(['name' => '子项', 'parent_id' => $top->id]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->put(
            route('admin.manage.knowledge-bases.groups.update', ['knowledgeBase' => $this->kb->id,
                'group' => $top->id,
            ]),
            ['name' => '使用手册', 'parent_id' => $other->id]
        )
        ->assertSessionHasErrors(['parent_id']);

    expect($top->fresh()->parent_id)->toBeNull();
});

test('分组不能挂到自身下', function () {
    $group = createKnowledgeGroupTestNode(['name' => '使用手册']);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->put(
            route('admin.manage.knowledge-bases.groups.update', ['knowledgeBase' => $this->kb->id,
                'group' => $group->id,
            ]),
            ['name' => '使用手册', 'parent_id' => $group->id]
        )
        ->assertSessionHasErrors(['parent_id']);
});

test('分组不能挂到二级分组下', function () {
    $top = createKnowledgeGroupTestNode(['name' => '使用手册']);
    $child = createKnowledgeGroupTestNode(['name' => '快速入门', 'parent_id' => $top->id]);
    $orphan = createKnowledgeGroupTestNode(['name' => '游离顶级']);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->put(
            route('admin.manage.knowledge-bases.groups.update', ['knowledgeBase' => $this->kb->id,
                'group' => $orphan->id,
            ]),
            ['name' => '游离顶级', 'parent_id' => $child->id]
        )
        ->assertSessionHasErrors(['parent_id']);
});

test('默认分组不能编辑或删除', function () {
    $defaultGroup = $this->kb->defaultDocumentGroup()->firstOrFail();

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->put(
            route('admin.manage.knowledge-bases.groups.update', ['knowledgeBase' => $this->kb->id,
                'group' => $defaultGroup->id,
            ]),
            ['name' => '新的默认分组', 'parent_id' => '']
        )
        ->assertSessionHasErrors(['group']);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->delete(
            route('admin.manage.knowledge-bases.groups.destroy', ['knowledgeBase' => $this->kb->id,
                'group' => $defaultGroup->id,
            ])
        )
        ->assertSessionHasErrors(['group']);
});

test('包含文档的普通分组不能删除', function () {
    $group = createKnowledgeGroupTestNode(['name' => '使用手册']);
    KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'group_id' => $group->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->delete(
            route('admin.manage.knowledge-bases.groups.destroy', ['knowledgeBase' => $this->kb->id,
                'group' => $group->id,
            ])
        )
        ->assertSessionHasErrors(['group']);

    expect(KnowledgeGroup::query()->whereKey($group->id)->exists())->toBeTrue();
});

test('删除空分组成功，但包含子分组的分组无法删除', function () {
    $top = createKnowledgeGroupTestNode(['name' => '使用手册']);
    $child = createKnowledgeGroupTestNode(['name' => '快速入门', 'parent_id' => $top->id]);

    // 顶级有子分组 → 删除失败
    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->delete(
            route('admin.manage.knowledge-bases.groups.destroy', ['knowledgeBase' => $this->kb->id,
                'group' => $top->id,
            ])
        )
        ->assertSessionHasErrors(['group']);

    expect(KnowledgeGroup::query()->whereKey($top->id)->exists())->toBeTrue();

    // 删掉子分组后再删顶级 → 成功
    $this->actingAs($this->user)
        ->delete(
            route('admin.manage.knowledge-bases.groups.destroy', ['knowledgeBase' => $this->kb->id,
                'group' => $child->id,
            ])
        )
        ->assertRedirect();

    $this->actingAs($this->user)
        ->delete(
            route('admin.manage.knowledge-bases.groups.destroy', ['knowledgeBase' => $this->kb->id,
                'group' => $top->id,
            ])
        )
        ->assertRedirect();

    expect(KnowledgeGroup::query()->whereKey($top->id)->exists())->toBeFalse();
});
