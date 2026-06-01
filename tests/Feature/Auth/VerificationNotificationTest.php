<?php

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use App\Settings\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('发送验证通知', function () {
    Notification::fake();

    /** @var MailSettings $settings */
    $settings = app(MailSettings::class);
    $settings->enabled = true;
    $settings->save();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertSentTo($user, QueuedVerifyEmail::class, function (QueuedVerifyEmail $notification) {
        return $notification instanceof ShouldQueue;
    });
});

test('不会发送验证通知当邮件服务器禁用时', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertNothingSent();
});

test('不会发送验证通知如果邮箱是已验证', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('dashboard', absolute: false));

    Notification::assertNothingSent();
});

test('不会发送验证通知到超级管理员', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create([
        'is_super_admin' => true,
    ]);

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertNothingSent();
});
