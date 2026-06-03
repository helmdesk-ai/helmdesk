<?php

namespace App\Actions\Teammate;

use App\Data\EnumOptionData;
use App\Data\Teammate\ListTeammateItemData;
use App\Data\Teammate\ShowTeammateListPagePropsData;
use App\Enums\UserOnlineStatus;
use App\Enums\UserPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示后台客服账号列表。
 */
class ShowTeammateListAction
{
    use AsAction;

    /**
     * 查询可管理的客服账号并组装列表页数据。
     */
    public function handle(User $actor): ShowTeammateListPagePropsData
    {
        Gate::forUser($actor)->authorize('user.permission', UserPermission::UsersView);

        $users = User::query()
            ->where('is_super_admin', false)
            ->orderBy('name')
            ->get();

        return new ShowTeammateListPagePropsData(
            user_list: $users
                ->map(fn (User $user) => ListTeammateItemData::fromModel(
                    user: $user,
                    canEdit: Gate::forUser($actor)->allows('users.updateProfile', $user),
                    canDelete: Gate::forUser($actor)->allows('users.removeMember', $user),
                    canResetTwoFactor: Gate::forUser($actor)->allows('users.updateProfile', $user),
                ))
                ->all(),
            online_status_options: EnumOptionData::fromCases(UserOnlineStatus::cases()),
            can_create: Gate::forUser($actor)->allows('user.permission', UserPermission::UsersCreate),
        );
    }

    /**
     * 渲染客服账号列表页。
     */
    public function asController(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('teammates/Index', $this->handle($actor)->toArray());
    }
}
