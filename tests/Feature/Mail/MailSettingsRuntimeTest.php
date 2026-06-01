<?php

use App\Models\User;
use App\Services\Mail\ApplyMailSettings;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('已启用SMTP设置会应用到邮件运行时配置', function () {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'smtp';
    $settings->from_address = 'noreply@example.com';
    $settings->from_name = 'HelmDesk';
    $settings->smtp_host = 'smtp.example.com';
    $settings->smtp_port = 587;
    $settings->smtp_encryption = 'starttls';
    $settings->smtp_username = 'smtp-user';
    $settings->smtp_password = 'smtp-secret';
    $settings->smtp_timeout = 15;
    $settings->smtp_local_domain = 'mail.example.com';
    $settings->save();

    app(ApplyMailSettings::class)->apply();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.from.address'))->toBe('noreply@example.com')
        ->and(config('mail.from.name'))->toBe('HelmDesk')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('mail.mailers.smtp.username'))->toBe('smtp-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('smtp-secret')
        ->and(config('mail.mailers.smtp.timeout'))->toBe(15)
        ->and(config('mail.mailers.smtp.local_domain'))->toBe('mail.example.com');
});

test('已启用Resend设置会应用到邮件运行时配置', function () {
    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'resend';
    $settings->from_address = 'noreply@example.com';
    $settings->resend_key = 're_test_key';
    $settings->save();

    app(ApplyMailSettings::class)->apply();

    expect(config('mail.default'))->toBe('resend')
        ->and(config('mail.mailers.resend.transport'))->toBe('resend')
        ->and(config('services.resend.key'))->toBe('re_test_key');
});

test('认证邮件回调应用已保存邮件设置在构建消息前', function () {
    config([
        'app.url' => 'https://local.test',
        'mail.default' => 'log',
        'mail.from.address' => 'hello@example.com',
        'mail.from.name' => 'Example',
    ]);

    // 配置了真实主机地址，认证邮件回调应把它同步进 app.url。
    $general = app(GeneralSettings::class);
    $general->base_url = 'https://support.example.test';
    $general->save();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->driver = 'smtp';
    $settings->from_address = 'noreply@example.com';
    $settings->from_name = 'HelmDesk';
    $settings->smtp_host = 'smtp.example.com';
    $settings->smtp_port = 587;
    $settings->save();

    $user = User::factory()->unverified()->create();

    (new VerifyEmail)->toMail($user);

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('app.url'))->toBe('https://support.example.test')
        ->and(config('mail.from.address'))->toBe('noreply@example.com')
        ->and(config('mail.from.name'))->toBe('HelmDesk');
});
