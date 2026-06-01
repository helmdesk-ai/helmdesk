<?php

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->superAdmin = createSuperAdmin();
});

test('超级管理员可以查看系统用户列表页面', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
        'name' => '普通用户A',
        'email' => 'u-a@example.com',
        'two_factor_confirmed_at' => now(),
        'avatar' => 'https://example.com/a.png',
    ]);

    $super = createSuperAdmin([
        'name' => '超级管理员',
        'email' => 'sa@example.com',
    ]);

    $this->actingAs($this->superAdmin, 'admin')
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/user/List')
            ->has('user_list')
            ->has('user_list_pagination')
            ->where('user_list_pagination.current_page', 1)
            ->where('user_list.0.email', $user->email)
            ->where('user_list.0.two_factor_enabled', true)
            ->etc()
        );

    expect(User::query()->whereKey($super->id)->exists())->toBeTrue();
});

test('非超级管理员不能访问系统用户页面', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
    ]);

    $this->actingAs($user, 'admin')
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('未认证用户不能访问系统用户页面', function () {
    $this->get(route('admin.users.index'))
        ->assertRedirect('/login');
});

test('超级管理员可以创建非超级管理员用户', function () {
    $avatar = Attachment::factory()->create([
        'workspace_id' => null,
        'purpose' => 'avatar',
        'status' => 'uploaded',
    ]);

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.users.store'), [
            'name' => '新用户',
            'email' => 'new-user@example.com',
            'avatar_id' => $avatar->id,
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])
        ->assertRedirect(route('admin.users.index'));

    $created = User::query()->where('email', 'new-user@example.com')->firstOrFail();
    expect($created->is_super_admin)->toBeFalse();
    expect($created->email_verified_at)->not->toBeNull();
    expect(Hash::check('secret1234', $created->password))->toBeTrue()
        ->and($created->avatar)->toStartWith('/attachments/dl?')
        ->and($avatar->fresh()->attachable_id)->toBe($created->id);
});

test('系统用户列表支持分页参数', function () {
    User::factory()->count(12)->create([
        'is_super_admin' => false,
    ]);

    $this->actingAs($this->superAdmin, 'admin')
        ->get(route('admin.users.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/user/List')
            ->where('user_list_pagination.current_page', 2)
            ->where('user_list_pagination.per_page', 10)
            ->where('user_list_pagination.total', 12)
            ->etc()
        );
});

test('超级管理员可以更新用户且不改变密码', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
        'name' => '旧名',
        'email' => 'u-edit@example.com',
        'password' => Hash::make('old-password'),
    ]);

    $oldHash = $user->password;

    $this->actingAs($this->superAdmin, 'admin')
        ->put(route('admin.users.update', ['id' => $user->id]), [
            'name' => '新名',
            'email' => 'u-edit@example.com',
            'avatar_id' => null,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertRedirect(route('admin.users.index'));

    $user->refresh();
    expect($user->name)->toBe('新名');
    expect($user->password)->toBe($oldHash);
});

test('超级管理员可以替换用户头像按附件ID', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
        'name' => '旧名',
        'email' => 'u-avatar@example.com',
    ]);
    $oldAvatar = Attachment::factory()->create([
        'workspace_id' => null,
        'purpose' => 'avatar',
        'status' => 'attached',
        'attachable_type' => $user->getMorphClass(),
        'attachable_id' => $user->id,
    ]);
    $newAvatar = Attachment::factory()->create([
        'workspace_id' => null,
        'purpose' => 'avatar',
        'status' => 'uploaded',
    ]);
    $user->update(['avatar' => $oldAvatar->full_url]);

    $this->actingAs($this->superAdmin, 'admin')
        ->put(route('admin.users.update', ['id' => $user->id]), [
            'name' => '新名',
            'email' => 'u-avatar@example.com',
            'avatar_id' => $newAvatar->id,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertRedirect(route('admin.users.index'));

    $user->refresh();

    expect($user->avatar)->toStartWith('/attachments/dl?')
        ->and($newAvatar->fresh()->attachable_id)->toBe($user->id)
        ->and(Attachment::withTrashed()->findOrFail($oldAvatar->id)->trashed())->toBeTrue();
});

test('超级管理员不能编辑超级管理员通过系统用户路由', function () {
    $target = createSuperAdmin([
        'email' => 'sa2@example.com',
    ]);

    $this->actingAs($this->superAdmin, 'admin')
        ->get(route('admin.users.edit', ['id' => $target->id]))
        ->assertNotFound();
});

test('超级管理员可以重置用户双因素认证', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
        'email' => 'u-2fa@example.com',
    ]);

    expect($user->two_factor_confirmed_at)->not->toBeNull();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_recovery_codes)->not->toBeNull();

    $this->actingAs($this->superAdmin, 'admin')
        ->from(route('admin.users.index'))
        ->put(route('admin.users.two-factor.reset', ['id' => $user->id]))
        ->assertRedirect(route('admin.users.index'));

    $user->refresh();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
});

test('超级管理员不能重置双因素认证用于超级管理员', function () {
    $target = createSuperAdmin([
        'email' => 'sa-2fa@example.com',
    ]);

    $this->actingAs($this->superAdmin, 'admin')
        ->put(route('admin.users.two-factor.reset', ['id' => $target->id]))
        ->assertNotFound();
});

test('非超级管理员不能重置用户双因素认证', function () {
    $actor = User::factory()->create([
        'is_super_admin' => false,
    ]);

    $user = User::factory()->create([
        'is_super_admin' => false,
    ]);

    $this->actingAs($actor, 'admin')
        ->put(route('admin.users.two-factor.reset', ['id' => $user->id]))
        ->assertForbidden();
});
