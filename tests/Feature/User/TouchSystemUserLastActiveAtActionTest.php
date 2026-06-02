<?php

use App\Actions\User\TouchSystemUserLastActiveAtAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('它刷新过期后台用户最后活跃时间戳', function () {
    [, $user] = createSystemWithOwner();
    $previousLastActiveAt = now()->subMinutes(10);
    $user->forceFill(['last_active_at' => $previousLastActiveAt])->save();

    TouchSystemUserLastActiveAtAction::run((string) $user->id);

    expect($user->fresh()->last_active_at)->not->toBeNull()
        ->and($user->fresh()->last_active_at->isAfter($previousLastActiveAt))->toBeTrue();
});

test('它跳过刷新最近活跃后台用户', function () {
    [, $user] = createSystemWithOwner();
    $recentLastActiveAt = now()->subSeconds(30)->startOfSecond();
    $user->forceFill(['last_active_at' => $recentLastActiveAt])->save();

    TouchSystemUserLastActiveAtAction::run((string) $user->id);

    expect($user->fresh()->last_active_at->toDateTimeString())
        ->toBe($recentLastActiveAt->toDateTimeString());
});
