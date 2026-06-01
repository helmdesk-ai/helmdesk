<?php

use App\Enums\UserOnlineStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace([], [
        'name' => 'Test Workspace',
    ]);
});

test('已认证用户可以查看用户列表页面', function () {
    $member = User::factory()->create([
        'name' => '客服A',
        'email' => 'a@example.com',
    ]);
    $this->workspace->users()->attach($member->id, [
        'role' => 'operator',
        'online_status' => UserOnlineStatus::Offline->value,
    ]);

    $this->actingAs($this->user)
        ->get(route('workspace.manage.teammates.index', ['slug' => $this->workspaceSlug()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teammate/List')
            ->has('user_list')
            ->has('user_list.0', fn (Assert $item) => $item
                ->hasAll(['user_id', 'user_name', 'user_avatar', 'user_email', 'role', 'user_online_status', 'show_remove_button'])
                ->etc()
            )
        );
});

test('操作员不能访问管理中心页面', function () {
    $workspace = $this->workspace;

    $operator = User::factory()->create([
        'name' => '普通客服',
        'email' => 'operator@example.com',
    ]);
    $workspace->users()->attach($operator->id, ['role' => 'operator']);

    $this->actingAs($operator)
        ->get(route('workspace.manage.workspaces.current.show', ['slug' => $this->workspaceSlug()]))
        ->assertForbidden();

    $this->actingAs($operator)
        ->get(route('workspace.manage.teammates.index', ['slug' => $this->workspaceSlug()]))
        ->assertForbidden();
});

test('工作区成员可以更新自己的在线状态来自侧边栏', function () {
    $operator = User::factory()->create([
        'name' => '普通客服',
        'email' => 'operator-online@example.com',
    ]);
    $this->workspace->users()->attach($operator->id, [
        'role' => 'operator',
        'online_status' => UserOnlineStatus::Offline->value,
    ]);

    $this->actingAs($operator)
        ->put(route('workspace.online-status.update', ['slug' => $this->workspaceSlug()]), [
            'online_status' => UserOnlineStatus::Online->value,
        ])
        ->assertRedirect();

    expect($this->workspace->users()->whereKey($operator->id)->firstOrFail()->pivot->online_status)
        ->toBe(UserOnlineStatus::Online->value);
});

test('已认证用户可以查看创建同事页面并包含选项', function () {
    User::factory()->create([
        'name' => '待添加用户',
        'email' => 'available@example.com',
    ]);

    $this->actingAs($this->user)
        ->get(route('workspace.manage.teammates.create', ['slug' => $this->workspaceSlug()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teammate/Create')
            ->has('role_options', 2)
            ->has('available_users')
            ->where('role_options.0.value', 'admin')
            ->where('role_options.1.value', 'operator')
            ->etc()
        );
});

test('已认证用户可以查看编辑用户页面并包含角色选项', function () {
    $member = User::factory()->create([
        'name' => '客服Z',
        'email' => 'z@example.com',
    ]);
    $this->workspace->users()->attach($member->id, ['role' => 'operator']);

    $this->actingAs($this->user)
        ->get(route('workspace.manage.teammates.edit', ['slug' => $this->workspaceSlug(), 'id' => $member->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teammate/Edit')
            ->has('user_form')
            ->has('role_options', 2)
            ->where('role_options.0.value', 'admin')
            ->where('role_options.1.value', 'operator')
            ->etc()
        );
});

test('可以添加现有用户为当前工作区', function () {
    $candidate = User::factory()->create([
        'name' => '客服B',
        'email' => 'b@example.com',
    ]);

    $this->actingAs($this->user)
        ->post(route('workspace.manage.teammates.store', ['slug' => $this->workspaceSlug()]), [
            'user_id' => $candidate->id,
            'nickname' => '小B',
            'role' => 'operator',
        ])
        ->assertRedirect(route('workspace.manage.teammates.index', ['slug' => $this->workspaceSlug()]));

    expect($this->workspace->users()->whereKey($candidate->id)->exists())->toBeTrue();
    expect($this->workspace->users()->whereKey($candidate->id)->firstOrFail()->pivot->role)->toBe('operator');
    expect($this->workspace->users()->whereKey($candidate->id)->firstOrFail()->pivot->nickname)->toBe('小B');
});

test('不能添加用户并带所有者角色', function () {
    $candidate = User::factory()->create([
        'name' => '非法 Owner',
        'email' => 'owner-like@example.com',
    ]);

    $this->actingAs($this->user)
        ->post(route('workspace.manage.teammates.store', ['slug' => $this->workspaceSlug()]), [
            'user_id' => $candidate->id,
            'role' => 'owner',
        ])
        ->assertSessionHasErrors(['role']);
});

test('不能添加工作区所有者作为同事', function () {
    $member = User::factory()->create([
        'name' => 'Owner-外部同名无关',
        'email' => 'other@example.com',
    ]);

    $this->actingAs($this->user)
        ->post(route('workspace.manage.teammates.store', ['slug' => $this->workspaceSlug()]), [
            'user_id' => $this->user->id,
            'role' => 'operator',
        ])
        ->assertSessionHasErrors(['user_id']);
});

test('不能添加重复工作区成员', function () {
    $candidate = User::factory()->create([
        'name' => '客服重复',
        'email' => 'dup@example.com',
    ]);
    $this->workspace->users()->attach($candidate->id, ['role' => 'operator']);

    $this->actingAs($this->user)
        ->post(route('workspace.manage.teammates.store', ['slug' => $this->workspaceSlug()]), [
            'user_id' => $candidate->id,
            'role' => 'operator',
        ])
        ->assertSessionHasErrors(['user_id']);
});

test('所有者不能改变自己的角色', function () {
    $this->actingAs($this->user)
        ->put(route('workspace.manage.teammates.update', ['slug' => $this->workspaceSlug(), 'id' => $this->user->id]), [
            'role' => 'admin',
        ])
        ->assertForbidden();

    $role = $this->workspace->users()->whereKey($this->user->id)->firstOrFail()->pivot->role;
    expect($role)->toBe('owner');
});

test('所有者可以更新另一个用户角色', function () {
    $member = User::factory()->create([
        'name' => '客服D',
        'email' => 'd@example.com',
    ]);
    $this->workspace->users()->attach($member->id, ['role' => 'operator']);

    $this->actingAs($this->user)
        ->put(route('workspace.manage.teammates.update', ['slug' => $this->workspaceSlug(), 'id' => $member->id]), [
            'nickname' => '对外昵称D',
            'role' => 'admin',
        ])
        ->assertRedirect(route('workspace.manage.teammates.index', ['slug' => $this->workspaceSlug()]));

    expect($this->workspace->users()->whereKey($member->id)->firstOrFail()->pivot->role)->toBe('admin');
    expect($this->workspace->users()->whereKey($member->id)->firstOrFail()->pivot->nickname)->toBe('对外昵称D');
});

test('所有者不能设置另一个用户角色到所有者', function () {
    $member = User::factory()->create([
        'name' => '客服-不可升 Owner',
        'email' => 'no-owner@example.com',
    ]);
    $this->workspace->users()->attach($member->id, ['role' => 'operator']);

    $this->actingAs($this->user)
        ->put(route('workspace.manage.teammates.update', ['slug' => $this->workspaceSlug(), 'id' => $member->id]), [
            'role' => 'owner',
        ])
        ->assertForbidden();

    $role = $this->workspace->users()->whereKey($member->id)->firstOrFail()->pivot->role;
    expect($role)->toBe('operator');
});

test('管理员不能改变操作员角色', function () {
    $admin = User::factory()->create([
        'name' => '管理员X',
        'email' => 'admin-x@example.com',
    ]);
    $this->workspace->users()->attach($admin->id, ['role' => 'admin']);

    $member = User::factory()->create([
        'name' => '客服-角色不可被管理员改',
        'email' => 'no-admin-role-change@example.com',
    ]);
    $this->workspace->users()->attach($member->id, ['role' => 'operator']);

    $this->actingAs($admin)
        ->put(route('workspace.manage.teammates.update', ['slug' => $this->workspaceSlug(), 'id' => $member->id]), [
            'role' => 'admin',
        ])
        ->assertForbidden();

    $role = $this->workspace->users()->whereKey($member->id)->firstOrFail()->pivot->role;
    expect($role)->toBe('operator');
});

test('可以更新用户在线状态来自列表', function () {
    $member = User::factory()->create([
        'name' => '客服E',
        'email' => 'e@example.com',
    ]);
    $this->workspace->users()->attach($member->id, [
        'role' => 'operator',
        'online_status' => UserOnlineStatus::Offline->value,
    ]);

    $this->actingAs($this->user)
        ->put(route('workspace.manage.teammates.online-status.update', ['slug' => $this->workspaceSlug(), 'id' => $member->id]), [
            'online_status' => UserOnlineStatus::Online->value,
        ])
        ->assertRedirect();

    $member->refresh();
    expect($this->workspace->users()->whereKey($member->id)->firstOrFail()->pivot->online_status)
        ->toBe(UserOnlineStatus::Online->value);
});

test('不能更新用户在线状态且枚举值无效', function () {
    $member = User::factory()->create([
        'name' => '客服E-非法状态',
        'email' => 'e-invalid-status@example.com',
    ]);
    $this->workspace->users()->attach($member->id, [
        'role' => 'operator',
        'online_status' => UserOnlineStatus::Offline->value,
    ]);

    $this->actingAs($this->user)
        ->put(route('workspace.manage.teammates.online-status.update', ['slug' => $this->workspaceSlug(), 'id' => $member->id]), [
            'online_status' => 2,
        ])
        ->assertSessionHasErrors('online_status');

    expect($this->workspace->users()->whereKey($member->id)->firstOrFail()->pivot->online_status)
        ->toBe(UserOnlineStatus::Offline->value);
});

test('不能删除当前已登录用户', function () {
    $this->actingAs($this->user)
        ->delete(route('workspace.manage.teammates.destroy', ['slug' => $this->workspaceSlug(), 'id' => $this->user->id]))
        ->assertForbidden();
});

test('删除同事只从中分离工作区', function () {
    $member = User::factory()->create([
        'name' => '待移除客服',
        'email' => 'remove@example.com',
    ]);
    $this->workspace->users()->attach($member->id, ['role' => 'operator']);

    $this->actingAs($this->user)
        ->delete(route('workspace.manage.teammates.destroy', ['slug' => $this->workspaceSlug(), 'id' => $member->id]))
        ->assertRedirect();

    expect(User::query()->whereKey($member->id)->exists())->toBeTrue();
    expect($this->workspace->users()->whereKey($member->id)->exists())->toBeFalse();
});

test('管理员可以分离操作员来自工作区', function () {
    $admin = User::factory()->create([
        'name' => '管理员Y',
        'email' => 'admin-y@example.com',
    ]);
    $this->workspace->users()->attach($admin->id, ['role' => 'admin']);

    $operator = User::factory()->create([
        'name' => '客服-可被管理员移除',
        'email' => 'operator-remove@example.com',
    ]);
    $this->workspace->users()->attach($operator->id, ['role' => 'operator']);

    $this->actingAs($admin)
        ->delete(route('workspace.manage.teammates.destroy', ['slug' => $this->workspaceSlug(), 'id' => $operator->id]))
        ->assertRedirect();

    expect($this->workspace->users()->whereKey($operator->id)->exists())->toBeFalse();
});

test('管理员不能分离另一个管理员来自工作区', function () {
    $admin = User::factory()->create([
        'name' => '管理员1',
        'email' => 'admin1@example.com',
    ]);
    $this->workspace->users()->attach($admin->id, ['role' => 'admin']);

    $admin2 = User::factory()->create([
        'name' => '管理员2',
        'email' => 'admin2@example.com',
    ]);
    $this->workspace->users()->attach($admin2->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->delete(route('workspace.manage.teammates.destroy', ['slug' => $this->workspaceSlug(), 'id' => $admin2->id]))
        ->assertForbidden();

    expect($this->workspace->users()->whereKey($admin2->id)->exists())->toBeTrue();
});
