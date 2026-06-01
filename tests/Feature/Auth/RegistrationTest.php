<?php

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

test('注册页面可以渲染', function () {
    $response = get(route('register'));

    $response->assertStatus(200);
});

test('第一个注册用户是超级管理员且无需邮箱验证即可进入系统设置', function () {
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
    expect($user->workspaces()->count())->toBe(0);

    Notification::assertNothingSent();
    $response->assertRedirect(route('admin.home', absolute: false));
});

test('第一个注册的超级管理员会忽略非管理员预期重定向', function () {
    $response = $this->withSession([
        'url.intended' => '/dashboard',
    ])->post(route('register.store'), [
        'name' => 'adminuser',
        'email' => 'admin@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(Auth::guard('web')->check())->toBeFalse();
    expect(Auth::guard('admin')->check())->toBeTrue();
    $response->assertRedirect(route('admin.home', absolute: false));
});

test('新用户可以注册', function () {
    Notification::fake();

    User::factory()->create();

    $response = post(route('register.store'), [
        'name' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('admin')->check())->toBeFalse();

    // 验证注册时自动创建了租户
    $user = User::query()->findOrFail(Auth::guard('web')->id());
    Notification::assertNothingSent();

    expect($user->workspaces()->count())->toBe(1);
    $workspace = $user->workspaces()->first();

    // 新工作区初始不再预置任何 AI 供应商，由用户在管理页按品牌按需添加。
    expect($workspace)->not->toBeNull()
        ->and($workspace->aiProviders()->exists())->toBeFalse();

    $response->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))
        ->assertRedirect(route('workspace.dashboard', ['slug' => $workspace->slug]));
});

test('新用户接收验证邮箱当邮件服务器启用时', function () {
    Notification::fake();

    User::factory()->create();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->save();

    $response = post(route('register.store'), [
        'name' => 'mailuser',
        'email' => 'mail@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'mail@example.com')->firstOrFail();

    Notification::assertSentTo($user, QueuedVerifyEmail::class, function (QueuedVerifyEmail $notification) {
        return $notification instanceof ShouldQueue;
    });
    $response->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))
        ->assertRedirect(route('verification.notice', absolute: false));
});

test('注册存储已提交的语言偏好', function () {
    User::factory()->create();

    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->post(route('register.store'), [
            'name' => 'localizeduser',
            'email' => 'localized@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'locale' => 'en',
            'timezone' => 'America/New_York',
        ])
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'localized@example.com')->firstOrFail();

    expect($user->locale)->toBe('en')
        ->and($user->timezone)->toBe('America/New_York')
        ->and($user->preferredLocale())->toBe('en');
});

test('注册未提交语言区域时会回退到请求语言', function () {
    User::factory()->create();

    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->post(route('register.store'), [
            'name' => 'browseruser',
            'email' => 'browser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'browser@example.com')->firstOrFail();

    expect($user->locale)->toBe('en');
});

test('注册可以禁用来自通用设置', function () {
    /** @var GeneralSettings $settings */
    $settings = app(GeneralSettings::class);
    $settings->allow_registration = false;
    $settings->save();

    get(route('register'))->assertRedirect(route('login'));

    post(route('register.store'), [
        'name' => 'closeduser',
        'email' => 'closed@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'closed@example.com')->exists())->toBeFalse();
});

test('新用户注册会忽略预期重定向并进入仪表盘', function () {
    User::factory()->create();

    $response = $this->withSession([
        'url.intended' => '/admin',
    ])->post(route('register.store'), [
        'name' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('admin')->check())->toBeFalse();
    $response->assertRedirect(route('dashboard', absolute: false));
});
