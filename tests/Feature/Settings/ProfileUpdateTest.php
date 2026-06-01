<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
});

test('配置档页面会显示', function () {
    $response = $this
        ->actingAs($this->user)
        ->get(route('settings.profile.edit', ['from_workspace' => $this->workspaceSlug()]));

    $response->assertOk();
});

test('配置档信息可以更新', function () {
    $response = $this
        ->actingAs($this->user)
        ->patch(route('settings.profile.update', ['from_workspace' => $this->workspaceSlug()]), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.profile.edit', ['from_workspace' => $this->workspaceSlug()]));

    $this->user->refresh();

    expect($this->user->name)->toBe('Test User');
    expect($this->user->email)->toBe('test@example.com');
    expect($this->user->email_verified_at)->toBeNull();
});

test('邮箱验证状态保持不变当邮箱地址保持不变', function () {
    $response = $this
        ->actingAs($this->user)
        ->patch(route('settings.profile.update', ['from_workspace' => $this->workspaceSlug()]), [
            'name' => 'Test User',
            'email' => $this->user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.profile.edit', ['from_workspace' => $this->workspaceSlug()]));

    expect($this->user->refresh()->email_verified_at)->not->toBeNull();
});

test('用户可以删除其账号', function () {
    $response = $this
        ->actingAs($this->user)
        ->delete(route('settings.profile.destroy', ['from_workspace' => $this->workspaceSlug()]), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($this->user->fresh())->toBeNull();
});

test('正确密码必须提供到删除账号', function () {
    $response = $this
        ->actingAs($this->user)
        ->from(route('settings.profile.edit', ['from_workspace' => $this->workspaceSlug()]))
        ->delete(route('settings.profile.destroy', ['from_workspace' => $this->workspaceSlug()]), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('settings.profile.edit', ['from_workspace' => $this->workspaceSlug()]));

    expect($this->user->fresh())->not->toBeNull();
});
