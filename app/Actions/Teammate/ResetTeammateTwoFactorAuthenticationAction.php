<?php

namespace App\Actions\Teammate;

use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 重置客服账号的两步验证配置。
 */
class ResetTeammateTwoFactorAuthenticationAction
{
    use AsAction;

    /**
     * 清空指定客服账号的两步验证密钥和恢复码。
     */
    public function handle(SystemContext $systemContext, User $actor, string $id): void
    {
        Gate::forUser($actor)->authorize('user.permission', UserPermission::UsersEdit);

        $user = $systemContext->users()
            ->where('is_super_admin', false)
            ->findOrFail($id);

        Gate::forUser($actor)->authorize('systemContext-users.updateProfile', [$systemContext, $user]);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * 接收两步验证重置请求并返回上一页。
     */
    public function asController(Request $request, string $teammate): RedirectResponse
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $actor = User::query()->findOrFail($ctx->user_id);

        $this->handle($ctx->systemContext(), $actor, $teammate);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('common.操作成功'),
        ]);

        return back();
    }
}
