<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

test('超级管理员可以打开工作区作为所有者在网页guard且不会丢失管理员guard', function () {
    $admin = createSuperAdmin();
    [$workspace, $owner] = createWorkspaceWithOwner();

    $response = actingAs($admin, 'admin')
        ->get(route('admin.workspaces.login-as-owner', ['id' => $workspace->id]));

    $response->assertRedirect(route('workspace.dashboard', ['slug' => $workspace->slug], absolute: false));
    expect(auth('admin')->id())->toBe($admin->id);
    expect(auth('web')->id())->toBe($owner->id);
});

test('登出网页guard不会影响管理员guard', function () {
    $admin = createSuperAdmin();
    [$workspace, $owner] = createWorkspaceWithOwner();

    actingAs($admin, 'admin')
        ->get(route('admin.workspaces.login-as-owner', ['id' => $workspace->id]));

    expect(auth('admin')->id())->toBe($admin->id);
    expect(auth('web')->id())->toBe($owner->id);

    post(route('logout.web'))
        ->assertRedirect(route('home', absolute: false));

    expect(auth('admin')->id())->toBe($admin->id);
    expect(auth('web')->check())->toBeFalse();
});
