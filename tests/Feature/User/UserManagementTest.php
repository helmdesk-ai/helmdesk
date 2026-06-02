<?php

use App\Enums\UserOnlineStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
});

test('后台用户可以从侧边栏更新自己的在线状态', function () {
    $this->actingAs($this->user)
        ->put(route('workspace.online-status.update'), [
            'online_status' => UserOnlineStatus::Online->value,
        ])
        ->assertRedirect();

    expect($this->user->fresh()->online_status)->toBe(UserOnlineStatus::Online);
});

test('后台用户在线状态拒绝无效枚举值', function () {
    $this->actingAs($this->user)
        ->put(route('workspace.online-status.update'), [
            'online_status' => 2,
        ])
        ->assertSessionHasErrors('online_status');
});
