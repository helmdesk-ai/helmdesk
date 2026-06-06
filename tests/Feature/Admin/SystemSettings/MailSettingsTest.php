<?php

use App\Notifications\MailSettingsTestNotification;
use App\Settings\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = createSuperAdmin();
});

test('未认证用户不能视图邮件设置页面', function () {
    $this->get(route('admin.mail.show'))
        ->assertRedirect('/login');
});

test('超级管理员可以查看邮件设置页面并包含受支持的生产驱动', function () {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'smtp';
    $settings->from_address = 'noreply@example.com';
    $settings->smtp_host = 'smtp.example.com';
    $settings->save();

    $this->actingAs($this->user)
        ->get(route('admin.mail.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/systemSettings/MailSetting')
            ->where('settings.enabled', true)
            ->where('settings.driver', 'smtp')
            ->where('settings.from_address', 'noreply@example.com')
            ->where('settings.smtp_host', 'smtp.example.com')
            ->has('driver_options', 6)
            ->where('driver_options.0.value', 'smtp')
            ->where('driver_options.1.value', 'sendmail')
            ->where('driver_options.2.value', 'mailgun')
            ->where('driver_options.3.value', 'postmark')
            ->where('driver_options.4.value', 'resend')
            ->where('driver_options.5.value', 'ses-v2')
        );
});

test('超级管理员可以保存SMTP邮件设置', function () {
    $this->actingAs($this->user)
        ->put(route('admin.mail.update'), [
            'enabled' => true,
            'driver' => 'smtp',
            'from_address' => 'noreply@example.com',
            'from_name' => 'HelmDesk',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
            'smtp_username' => 'smtp-user',
            'smtp_password' => 'smtp-secret',
            'smtp_local_domain' => 'mail.example.com',
            'smtp_timeout' => 15,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class)->refresh();
    expect($settings->enabled)->toBeTrue()
        ->and($settings->driver)->toBe('smtp')
        ->and($settings->from_address)->toBe('noreply@example.com')
        ->and($settings->from_name)->toBe('HelmDesk')
        ->and($settings->smtp_host)->toBe('smtp.example.com')
        ->and($settings->smtp_port)->toBe(587)
        ->and($settings->smtp_encryption)->toBe('starttls')
        ->and($settings->smtp_username)->toBe('smtp-user')
        ->and($settings->smtp_password)->toBe('smtp-secret')
        ->and($settings->smtp_local_domain)->toBe('mail.example.com')
        ->and($settings->smtp_timeout)->toBe(15);
});

test('已禁用邮件设置可以被已保存没有驱动必需字段', function () {
    $this->actingAs($this->user)
        ->put(route('admin.mail.update'), [
            'enabled' => false,
            'driver' => 'mailgun',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class)->refresh();
    expect($settings->enabled)->toBeFalse()
        ->and($settings->driver)->toBe('mailgun');
});

test('已启用Mailgun设置需要API密钥', function () {
    $this->actingAs($this->user)
        ->put(route('admin.mail.update'), [
            'enabled' => true,
            'driver' => 'mailgun',
            'from_address' => 'noreply@example.com',
            'mailgun_domain' => 'mg.example.com',
            'mailgun_endpoint' => 'api.mailgun.net',
            'mailgun_scheme' => 'https',
        ])
        ->assertSessionHasErrors('mailgun_secret');
});

test('API凭证会被保留当更新载荷留下它们空白', function () {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'resend';
    $settings->from_address = 'noreply@example.com';
    $settings->resend_key = 'existing-resend-key';
    $settings->save();

    $this->actingAs($this->user)
        ->put(route('admin.mail.update'), [
            'enabled' => true,
            'driver' => 'resend',
            'from_address' => 'mail@example.com',
            'resend_key' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->from_address)->toBe('mail@example.com')
        ->and($settings->resend_key)->toBe('existing-resend-key');
});

test('API凭证可以显式清除', function () {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'resend';
    $settings->from_address = 'noreply@example.com';
    $settings->resend_key = 'existing-resend-key';
    $settings->save();

    $this->actingAs($this->user)
        ->put(route('admin.mail.update'), [
            'enabled' => false,
            'driver' => 'resend',
            'from_address' => 'mail@example.com',
            'resend_key' => '',
            'clear_resend_key' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->resend_key)->toBeNull();
});

test('已启用驱动不能清除其必需凭证且没有替换值', function () {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'resend';
    $settings->from_address = 'noreply@example.com';
    $settings->resend_key = 'existing-resend-key';
    $settings->save();

    $this->actingAs($this->user)
        ->put(route('admin.mail.update'), [
            'enabled' => true,
            'driver' => 'resend',
            'from_address' => 'mail@example.com',
            'resend_key' => '',
            'clear_resend_key' => true,
        ])
        ->assertSessionHasErrors('resend_key');
});

test('邮件设置会校验支持的驱动和地址字段', function (array $payload, string $field) {
    $this->actingAs($this->user)
        ->put(route('admin.mail.update'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'unsupported driver' => [[
        'enabled' => true,
        'driver' => 'array',
        'from_address' => 'noreply@example.com',
    ], 'driver'],
    'invalid from address' => [[
        'enabled' => true,
        'driver' => 'smtp',
        'from_address' => 'not-email',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
    ], 'from_address'],
    'missing smtp host' => [[
        'enabled' => true,
        'driver' => 'smtp',
        'from_address' => 'noreply@example.com',
        'smtp_port' => 587,
    ], 'smtp_host'],
    'cleared required resend key' => [[
        'enabled' => true,
        'driver' => 'resend',
        'from_address' => 'noreply@example.com',
        'resend_key' => '',
        'clear_resend_key' => true,
    ], 'resend_key'],
]);

test('未认证用户不能更新邮件设置', function () {
    $this->put(route('admin.mail.update'), [
        'enabled' => false,
        'driver' => 'smtp',
    ])
        ->assertRedirect('/login');
});

test('超级管理员可以发送测试邮件并使用已保存设置', function () {
    Notification::fake();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'smtp';
    $settings->from_address = 'noreply@example.com';
    $settings->from_name = 'HelmDesk';
    $settings->smtp_host = 'smtp.example.com';
    $settings->smtp_port = 587;
    $settings->save();

    $this->actingAs($this->user)
        ->post(route('admin.mail.test'), [
            'email' => 'admin@example.com',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(MailSettingsTestNotification::class, function (MailSettingsTestNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
        return $channels === ['mail']
            && $notifiable->routes['mail'] === 'admin@example.com'
            && $notification->toMail($notifiable)->subject === __('mail.test.subject');
    });
});

test('测试邮件需要已启用的已保存邮件设置', function () {
    Notification::fake();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = false;
    $settings->driver = 'smtp';
    $settings->from_address = 'noreply@example.com';
    $settings->smtp_host = 'smtp.example.com';
    $settings->smtp_port = 587;
    $settings->save();

    $this->actingAs($this->user)
        ->post(route('admin.mail.test'), [
            'email' => 'admin@example.com',
        ])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

test('未认证用户不能发送测试邮件', function () {
    $this->post(route('admin.mail.test'), [
        'email' => 'admin@example.com',
    ])
        ->assertRedirect('/login');
});
