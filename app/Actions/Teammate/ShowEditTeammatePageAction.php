<?php

namespace App\Actions\Teammate;

use App\Data\SystemUserContextData;
use App\Data\Teammate\EditTeammateData;
use App\Data\Teammate\PermissionGroupData;
use App\Data\Teammate\ShowEditTeammatePagePropsData;
use App\Enums\UserPermission;
use App\Models\SystemContext;
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
    public function handle(SystemContext $systemContext, User $actor, string $id): ShowEditTeammatePagePropsData
    {
        Gate::forUser($actor)->authorize('user.permission', UserPermission::UsersEdit);

        $user = $systemContext->users()
            ->where('is_super_admin', false)
            ->findOrFail($id);

        return new ShowEditTeammatePagePropsData(
            user_form: EditTeammateData::fromModel($user),
            permission_groups: PermissionGroupData::allGroups(),
            can_update_profile: Gate::forUser($actor)->allows('systemContext-users.updateProfile', [$systemContext, $user]),
        );
    }

    /**
     * 渲染客服账号编辑页面。
     */
    public function asController(Request $request, string $teammate): Response
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $actor = User::query()->findOrFail($ctx->user_id);

        return Inertia::render('teammates/Edit', $this->handle($ctx->systemContext(), $actor, $teammate)->toArray());
    }
}
