<?php

namespace App\Actions\Teammate;

use App\Data\Teammate\EditTeammateData;
use App\Data\Teammate\PermissionGroupData;
use App\Data\Teammate\ShowEditTeammatePagePropsData;
use App\Enums\UserPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示客服账号编辑页面。
 */
class ShowEditTeammatePageAction
{
    use AsAction;

    /**
     * 查询客服账号并组装编辑页数据。
     */
    public function handle(User $actor, string $id): ShowEditTeammatePagePropsData
    {
        Gate::forUser($actor)->authorize('user.permission', UserPermission::UsersEdit);

        $user = User::query()
            ->where('is_super_admin', false)
            ->findOrFail($id);

        return new ShowEditTeammatePagePropsData(
            user_form: EditTeammateData::fromModel($user),
            permission_groups: PermissionGroupData::allGroups(),
            can_update_profile: Gate::forUser($actor)->allows('users.updateProfile', $user),
        );
    }

    /**
     * 渲染客服账号编辑页面。
     */
    public function asController(Request $request, string $teammate): Response
    {
        $actor = $request->user();

        return Inertia::render('teammates/Edit', $this->handle($actor, $teammate)->toArray());
    }
}
