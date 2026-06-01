<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
});

test('密码更新页面会显示', function () {
    $response = $this
        ->actingAs($this->user)
        ->get(route('settings.password.edit', ['from_workspace' => $this->workspaceSlug()]));

    $response->assertStatus(200);
});

test('密码可以更新', function () {
    $response = $this
        ->actingAs($this->user)
        ->from(route('settings.password.edit', ['from_workspace' => $this->workspaceSlug()]))
        ->put(route('settings.password.update', ['from_workspace' => $this->workspaceSlug()]), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.password.edit', ['from_workspace' => $this->workspaceSlug()]))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => __('common.操作成功'),
        ]);

    expect(Hash::check('new-password', $this->user->refresh()->password))->toBeTrue();
});

test('正确密码必须提供到更新密码', function () {
    $response = $this
        ->actingAs($this->user)
        ->from(route('settings.password.edit', ['from_workspace' => $this->workspaceSlug()]))
        ->put(route('settings.password.update', ['from_workspace' => $this->workspaceSlug()]), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('settings.password.edit', ['from_workspace' => $this->workspaceSlug()]));
});
