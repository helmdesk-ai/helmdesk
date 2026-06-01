<?php

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Settings\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->save();
});

test('重置密码链接页面可以渲染', function () {
    $response = $this->get(route('password.request'));

    $response->assertStatus(200);
});

test('重置密码链接可以请求', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $notification) {
        return $notification instanceof ShouldQueue;
    });
});

test('重置密码页面可以渲染', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertStatus(200);

        return true;
    });
});

test('密码可以重置并使用有效令牌', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('重置密码链接不会发送当邮件服务器禁用时', function () {
    Notification::fake();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = false;
    $settings->save();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertNothingSent();
});

test('密码不能被重置并使用无效令牌', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
