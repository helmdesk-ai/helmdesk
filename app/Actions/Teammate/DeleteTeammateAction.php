<?php

namespace App\Actions\Teammate;

use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除客服账号。
 */
class DeleteTeammateAction
{
    use AsAction;

    /**
     * 将指定客服账号移入回收站。
     */
    public function handle(User $actor, string $id): void
    {
        Gate::forUser($actor)->authorize('user.permission', UserPermission::UsersDelete);

        $user = User::query()
            ->where('is_super_admin', false)
            ->findOrFail($id);

        Gate::forUser($actor)->authorize('users.removeMember', $user);

        $user->delete();
    }

    /**
     * 接收客服删除请求并返回上一页。
     */
    public function asController(Request $request, string $teammate): RedirectResponse
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $actor = User::query()->findOrFail($ctx->user_id);

        $this->handle($actor, $teammate);

        return back();
    }
}
