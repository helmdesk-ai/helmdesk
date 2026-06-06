<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('用户可以更新语言和时区偏好', function () {
    [, $user] = createSystemWithOwner([
        'locale' => 'zh-CN',
        'timezone' => 'Asia/Shanghai',
    ]);

    $this->actingAs($user)
        ->put(route('settings.language.update'), [
            'locale' => 'en',
            'timezone' => 'America/New_York',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->locale)->toBe('en')
        ->and($user->timezone)->toBe('America/New_York')
        ->and($user->preferredLocale())->toBe('en');
});

test('语言设置校验受支持偏好', function (array $payload, string $field) {
    [, $user] = createSystemWithOwner();

    $this->actingAs($user)
        ->put(route('settings.language.update'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'unsupported locale' => [[
        'locale' => 'fr',
        'timezone' => 'Asia/Shanghai',
    ], 'locale'],
    'invalid timezone' => [[
        'locale' => 'en',
        'timezone' => 'Not/A_Timezone',
    ], 'timezone'],
]);
