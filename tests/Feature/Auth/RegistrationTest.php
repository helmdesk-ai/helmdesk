<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

test('无用户时注册页面可以渲染', function () {
    get(route('register'))
        ->assertOk();
});

test('第一个注册用户是超级管理员且不创建工作区', function () {
    Notification::fake();

    $response = post(route('register.store'), [
        'name' => 'adminuser',
        'email' => 'admin@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(Auth::guard('web')->check())->toBeFalse();
    expect(Auth::guard('admin')->check())->toBeTrue();

    $user = User::query()->findOrFail(Auth::guard('admin')->id());
    expect($user->is_super_admin)->toBeTrue();

    Notification::assertNothingSent();
    $response->assertRedirect(route('admin.home', absolute: false));
});

test('已有用户后不允许自助注册', function () {
    User::factory()->create();

    get(route('register'))->assertRedirect(route('login'));

    post(route('register.store'), [
        'name' => 'closeduser',
        'email' => 'closed@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'closed@example.com')->exists())->toBeFalse();
});
