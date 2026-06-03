<?php

use App\Enums\UserOnlineStatus;
use App\Enums\UserPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->owner = $this->createUserWithSystem([
        'name' => 'owner',
        'email' => 'owner@example.com',
    ]);
});

test('普通用户可以进入后台但不能访问没有权限的客服管理', function (): void {
    $ordinaryUser = User::factory()->create([
        'name' => 'ordinary-user',
        'email' => 'ordinary@example.com',
        'is_super_admin' => false,
        'permissions' => [],
    ]);

    $this->actingAs($ordinaryUser, 'web')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('canAccessUsers', false)
            ->where('canManageSystemSettings', false)
            ->where('systemUserContext.user_email', 'ordinary@example.com')
        );

    $this->actingAs($ordinaryUser, 'web')
        ->get(route('admin.manage.teammates.index'))
        ->assertForbidden();
});

test('只有查看权限的用户可以查看客服列表但不能新增客服', function (): void {
    $viewer = User::factory()->create([
        'is_super_admin' => false,
        'permissions' => [UserPermission::UsersView->value],
    ]);

    $this->actingAs($viewer)
        ->get(route('admin.manage.teammates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teammates/Index')
            ->where('can_create', false)
        );

    $this->actingAs($viewer)
        ->get(route('admin.manage.teammates.create'))
        ->assertForbidden();
});

test('超级管理员可以查看客服管理列表', function (): void {
    User::factory()->create([
        'name' => 'support-agent',
        'email' => 'support@example.com',
        'is_super_admin' => false,
        'permissions' => [UserPermission::ContactsView->value],
        'nickname' => '在线客服',
        'online_status' => UserOnlineStatus::Online->value,
    ]);

    $this->actingAs($this->owner)
        ->get(route('admin.manage.teammates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teammates/Index')
            ->has('user_list', 1)
            ->where('user_list.0.email', 'support@example.com')
            ->where('user_list.0.nickname', '在线客服')
            ->where('user_list.0.permission_count', 1)
            ->where('user_list.0.online_status', UserOnlineStatus::Online->value)
            ->where('can_create', true)
        );
});

test('超级管理员可以创建带登录信息和权限的客服账号', function (): void {
    $this->actingAs($this->owner)
        ->post(route('admin.manage.teammates.store'), [
            'name' => 'support-agent',
            'email' => 'support@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'nickname' => '在线客服',
            'permissions' => [
                UserPermission::ContactsView->value,
                UserPermission::CannedRepliesEdit->value,
            ],
        ])
        ->assertRedirect(route('admin.manage.teammates.index'));

    $user = User::query()->where('email', 'support@example.com')->firstOrFail();

    expect($user->is_super_admin)->toBeFalse()
        ->and($user->permissions)->toBe([
            UserPermission::ContactsView->value,
            UserPermission::CannedRepliesEdit->value,
        ])
        ->and($user->nickname)->toBe('在线客服')
        ->and($user->online_status)->toBe(UserOnlineStatus::Online)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

test('超级管理员可以更新客服资料权限和密码', function (): void {
    $user = User::factory()->create([
        'name' => 'support-agent',
        'email' => 'support@example.com',
        'is_super_admin' => false,
        'permissions' => [UserPermission::ContactsView->value],
        'nickname' => '在线客服',
    ]);

    $this->actingAs($this->owner)
        ->put(route('admin.manage.teammates.update', ['teammate' => $user->id]), [
            'name' => 'support-editor',
            'email' => 'support-editor@example.com',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
            'nickname' => '高级客服',
            'permissions' => [
                UserPermission::KnowledgeBasesView->value,
                UserPermission::KnowledgeBasesEdit->value,
            ],
        ])
        ->assertRedirect(route('admin.manage.teammates.index'));

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('support-editor')
        ->and($fresh->email)->toBe('support-editor@example.com')
        ->and($fresh->nickname)->toBe('高级客服')
        ->and($fresh->permissions)->toBe([
            UserPermission::KnowledgeBasesView->value,
            UserPermission::KnowledgeBasesEdit->value,
        ])
        ->and(Hash::check('new-secret-password', $fresh->password))->toBeTrue();
});

test('超级管理员可以删除其他客服但不能删除自己', function (): void {
    $user = User::factory()->create([
        'is_super_admin' => false,
        'permissions' => [],
    ]);

    $this->actingAs($this->owner)
        ->delete(route('admin.manage.teammates.destroy', ['teammate' => $user->id]))
        ->assertRedirect();

    expect($user->fresh()->trashed())->toBeTrue();

    $this->actingAs($this->owner)
        ->delete(route('admin.manage.teammates.destroy', ['teammate' => $this->owner->id]))
        ->assertNotFound();
});

test('用户管理权限覆盖客服管理同组操作', function (): void {
    $manager = User::factory()->create([
        'is_super_admin' => false,
        'permissions' => [UserPermission::UsersManage->value],
    ]);

    $this->actingAs($manager)
        ->get(route('admin.manage.teammates.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teammates/Create')
            ->has('permission_groups')
        );

    $this->actingAs($manager)
        ->post(route('admin.manage.teammates.store'), [
            'name' => 'other-support',
            'email' => 'other-support@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'nickname' => null,
            'permissions' => [UserPermission::ContactsView->value],
        ])
        ->assertRedirect(route('admin.manage.teammates.index'));
});
