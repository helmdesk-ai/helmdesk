<?php

use App\Data\User\UserNotificationPreferencesData;
use App\Enums\NotificationSound;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

test('用户可以查看默认通知设置', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $this->actingAs($user)
        ->get(route('settings.notifications.edit', ['from_system' => $systemContext->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Notifications')
            ->where('preferences.browser_notifications_enabled', true)
            ->where('preferences.sound_enabled', true)
            ->where('preferences.sound', NotificationSound::Pop->value)
            ->where('preferences.notify_assigned_conversations', true)
            ->where('preferences.notify_unassigned_conversations', true)
            ->where('sound_options.0.value', NotificationSound::Pop->value)
        );
});

test('用户可以更新通知设置', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $this->actingAs($user)
        ->put(route('settings.notifications.update', ['from_system' => $systemContext->slug]), [
            'browser_notifications_enabled' => true,
            'sound_enabled' => true,
            'sound' => NotificationSound::Pop->value,
            'notify_assigned_conversations' => true,
            'notify_unassigned_conversations' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->notificationPreferences())
        ->toBeInstanceOf(UserNotificationPreferencesData::class)
        ->browser_notifications_enabled->toBeTrue()
        ->sound_enabled->toBeTrue()
        ->sound->toBe(NotificationSound::Pop)
        ->notify_assigned_conversations->toBeTrue()
        ->notify_unassigned_conversations->toBeTrue();
});

test('通知设置校验布尔开关', function (array $payload, string $field) {
    [$systemContext, $user] = createSystemWithOwner();

    $this->actingAs($user)
        ->put(route('settings.notifications.update', ['from_system' => $systemContext->slug]), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'browser notification flag' => [[
        'browser_notifications_enabled' => 'yes',
        'sound_enabled' => false,
        'sound' => NotificationSound::Note->value,
        'notify_assigned_conversations' => true,
        'notify_unassigned_conversations' => false,
    ], 'browser_notifications_enabled'],
    'sound flag' => [[
        'browser_notifications_enabled' => false,
        'sound_enabled' => 'yes',
        'sound' => NotificationSound::Note->value,
        'notify_assigned_conversations' => true,
        'notify_unassigned_conversations' => false,
    ], 'sound_enabled'],
    'sound' => [[
        'browser_notifications_enabled' => false,
        'sound_enabled' => true,
        'sound' => 'alarm',
        'notify_assigned_conversations' => true,
        'notify_unassigned_conversations' => false,
    ], 'sound'],
]);
