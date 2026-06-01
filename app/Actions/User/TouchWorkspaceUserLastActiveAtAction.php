<?php

namespace App\Actions\User;

use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 刷新工作区成员的最后活跃时间。
 */
class TouchWorkspaceUserLastActiveAtAction
{
    use AsAction;

    private const TOUCH_INTERVAL_MINUTES = 1;

    /**
     * 按最小间隔刷新成员在工作区内的最后活跃时间。
     */
    public function handle(Workspace $workspace, string $userId): void
    {
        $lastActiveAt = $workspace->pivot?->last_active_at
            ?? $workspace->users()->whereKey($userId)->value('user_workspace.last_active_at');

        if ($lastActiveAt !== null && Carbon::parse($lastActiveAt)->greaterThan(now()->subMinutes(self::TOUCH_INTERVAL_MINUTES))) {
            return;
        }

        $workspace->users()->updateExistingPivot($userId, [
            'last_active_at' => now(),
        ]);
    }
}
