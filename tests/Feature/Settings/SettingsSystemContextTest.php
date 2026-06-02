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

test('个人设置页面使用总管理后台上下文', function () {
    $user = $this->createUserWithSystem();

    $this->actingAs($user)
        ->get(route('settings.profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', (string) $user->id)
        );
});

test('超级管理员可以访问设置', function () {
    $user = createSuperAdmin();

    $this->actingAs($user, 'admin')
        ->get(route('settings.profile.edit'))
        ->assertOk();
});

test('当两个guard都已认证时设置页使用管理员身份', function () {
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
