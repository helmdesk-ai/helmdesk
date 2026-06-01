<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('超级管理员可以查看工作区回收站列表', function () {
    $admin = createSuperAdmin();

    $workspace = Workspace::factory()->create([
        'owner_id' => User::factory()->create()->id,
    ]);
    $workspace->delete();

    actingAs($admin, 'admin')
        ->get(route('admin.workspaces.trash'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/workspace/Trash')
            ->has('workspace_trash_list', 1)
            ->has('workspace_trash_list_pagination')
            ->where('workspace_trash_list_pagination.current_page', 1)
            ->where('workspace_trash_list.0.id', (string) $workspace->id)
            ->where('workspace_trash_list.0.name', $workspace->name)
            ->etc()
        );
});

test('工作区回收站列表支持分页参数', function () {
    $admin = createSuperAdmin();

    $owner = User::factory()->create();
    $workspaces = Workspace::factory()->count(12)->create([
        'owner_id' => $owner->id,
    ]);
    foreach ($workspaces as $ws) {
        $ws->delete();
    }

    actingAs($admin, 'admin')
        ->get(route('admin.workspaces.trash', ['page' => 2, 'per_page' => 10]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/workspace/Trash')
            ->has('workspace_trash_list', 2)
            ->where('workspace_trash_list_pagination.current_page', 2)
            ->where('workspace_trash_list_pagination.per_page', 10)
            ->where('workspace_trash_list_pagination.total', 12)
        );
});

test('超级管理员可以恢复已删除工作区', function () {
    $admin = createSuperAdmin();

    $workspace = Workspace::factory()->create([
        'owner_id' => User::factory()->create()->id,
    ]);
    $workspace->delete();

    actingAs($admin, 'admin')
        ->put(route('admin.workspaces.restore', ['id' => $workspace->id]))
        ->assertRedirect();

    expect(Workspace::onlyTrashed()->whereKey($workspace->id)->exists())->toBeFalse();
    expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
});

test('非超级管理员不能查看或恢复工作区回收站', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
    ]);
    $workspace = Workspace::factory()->create([
        'owner_id' => User::factory()->create()->id,
    ]);
    $workspace->delete();

    actingAs($user, 'admin')
        ->get(route('admin.workspaces.trash'))
        ->assertForbidden();

    actingAs($user, 'admin')
        ->put(route('admin.workspaces.restore', ['id' => $workspace->id]))
        ->assertForbidden();
});
