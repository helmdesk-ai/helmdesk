<?php

use App\Actions\User\TouchWorkspaceUserLastActiveAtAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('它刷新过期工作区成员最后活跃时间戳', function () {
    [$workspace, $user] = createWorkspaceWithOwner();
    $previousLastActiveAt = now()->subMinutes(10);

    $user->workspaces()->updateExistingPivot($workspace->id, [
        'last_active_at' => $previousLastActiveAt,
    ]);

    TouchWorkspaceUserLastActiveAtAction::run($workspace, (string) $user->id);

    $updatedLastActiveAt = DB::table('user_workspace')
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->value('last_active_at');

    expect($updatedLastActiveAt)->not->toBeNull()
        ->and(Carbon::parse((string) $updatedLastActiveAt)->isAfter($previousLastActiveAt))->toBeTrue();
});

test('它跳过刷新最近活跃工作区成员', function () {
    [$workspace, $user] = createWorkspaceWithOwner();
    $recentLastActiveAt = now()->subSeconds(30)->startOfSecond();

    $user->workspaces()->updateExistingPivot($workspace->id, [
        'last_active_at' => $recentLastActiveAt,
    ]);

    TouchWorkspaceUserLastActiveAtAction::run($workspace, (string) $user->id);

    $updatedLastActiveAt = DB::table('user_workspace')
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->value('last_active_at');

    expect(Carbon::parse((string) $updatedLastActiveAt)->toDateTimeString())
        ->toBe($recentLastActiveAt->toDateTimeString());
});
