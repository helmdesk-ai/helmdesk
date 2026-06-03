<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 刷新后台用户的最后活跃时间。
 */
class TouchSystemUserLastActiveAtAction
{
    use AsAction;

    private const TOUCH_INTERVAL_MINUTES = 1;

    /**
     * 按最小间隔刷新用户最后活跃时间。
     */
    public function handle(User $user): void
    {
        $lastActiveAt = $user->last_active_at;

        if ($lastActiveAt !== null && Carbon::parse($lastActiveAt)->greaterThan(now()->subMinutes(self::TOUCH_INTERVAL_MINUTES))) {
            return;
        }

        $user->forceFill([
            'last_active_at' => now(),
        ])->save();
    }
}
