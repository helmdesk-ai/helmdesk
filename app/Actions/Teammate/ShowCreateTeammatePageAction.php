<?php

namespace App\Actions\Teammate;

use App\Data\Teammate\PermissionGroupData;
use App\Data\Teammate\ShowCreateTeammatePagePropsData;
use App\Enums\UserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示客服账号创建页面。
 */
class ShowCreateTeammatePageAction
{
    use AsAction;

    /**
     * 组装客服创建页权限分组。
     */
    public function handle(): ShowCreateTeammatePagePropsData
    {
        return new ShowCreateTeammatePagePropsData(
            permission_groups: PermissionGroupData::allGroups(),
        );
    }

    /**
     * 渲染客服创建页面。
     */
    public function asController(Request $request): Response
    {
        Gate::authorize('user.permission', UserPermission::UsersCreate);

        return Inertia::render('teammates/Create', $this->handle()->toArray());
    }
}
