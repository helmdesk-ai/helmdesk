<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

test('后台用户可以访问个人设置且不需要系统参数', function () {
    $user = $this->createUserWithSystem();

    $this->actingAs($user)
        ->get(route('settings.profile.edit'))
        ->assertOk();
});

test('旧 from_system 参数不再影响个人设置访问', function () {
    $user = $this->createUserWithSystem();

    $this->actingAs($user)
        ->get(route('settings.profile.edit', ['from_system' => 'not-exists']))
        ->assertOk();
});

test('超级管理员可以访问设置且不带from_system', function () {
    $user = createSuperAdmin();

    $this->actingAs($user, 'admin')
        ->get(route('settings.profile.edit'))
        ->assertOk();
});

test('当两个guard都已认证时，不带from_system的设置页使用管理员身份', function () {
    $admin = createSuperAdmin();

    $owner = $this->createUserWithSystem();

    $this->actingAs($admin, 'admin');
    $this->actingAs($owner, 'web');

    $this->get(route('settings.profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', (string) $admin->id)
        );
});
