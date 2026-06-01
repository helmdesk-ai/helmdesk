<?php

use App\Models\User;
use App\Settings\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

test('登录页面可以渲染', function () {
    $response = $this->get(route('login'));

    $response->assertStatus(200);
});

test('用户可以认证使用登录页面', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('web');
    $this->assertGuest('admin');
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('未验证用户可以认证，但仅在邮件服务器启用时被拦截', function () {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->save();

    $user = User::factory()->withoutTwoFactor()->unverified()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('web');
    $this->assertGuest('admin');
    $response->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))
        ->assertRedirect(route('verification.notice', absolute: false));
});

test('未验证用户可以进入工作区后台当邮件服务器禁用时', function () {
    [$workspace, $user] = createWorkspaceWithOwner([
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('workspace.dashboard', ['slug' => $workspace->slug]));
});

test('普通用户登录会忽略预期重定向并进入仪表盘', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->withSession([
        'url.intended' => '/admin',
    ])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('web');
    $this->assertGuest('admin');
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('超级管理员会被重定向到系统设置之后登录', function () {
    $user = User::factory()->withoutTwoFactor()->create([
        'is_super_admin' => true,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('admin');
    $this->assertGuest('web');
    $response->assertRedirect('/admin');
});

test('未验证超级管理员可以认证到系统设置', function () {
    $user = User::factory()->withoutTwoFactor()->unverified()->create([
        'is_super_admin' => true,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('admin');
    $this->assertGuest('web');
    $response->assertRedirect('/admin');
});

test('超级管理员登录会忽略非管理员预期重定向', function () {
    $user = User::factory()->withoutTwoFactor()->create([
        'is_super_admin' => true,
    ]);

    $response = $this->withSession([
        'url.intended' => '/dashboard',
    ])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('admin');
    $this->assertGuest('web');
    $response->assertRedirect('/admin');
});

test('启用双因素认证的用户会被重定向到双因素挑战', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('用户不能认证且无效密码', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('用户可以登出', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest('web');
    $response->assertRedirect(route('home'));
});

test('用户会被限流', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
