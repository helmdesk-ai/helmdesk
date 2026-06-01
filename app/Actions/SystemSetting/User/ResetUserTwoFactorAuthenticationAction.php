<?php

namespace App\Actions\SystemSetting\User;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 重置指定用户的两步验证配置。
 */
class ResetUserTwoFactorAuthenticationAction
{
    use AsAction;

    public function handle(string $id): void
    {
        $user = User::query()
            ->where('is_super_admin', false)
            ->findOrFail($id);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function asController(Request $request, string $id): RedirectResponse
    {
        $this->handle($id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('common.操作成功'),
        ]);

        return back();
    }
}
