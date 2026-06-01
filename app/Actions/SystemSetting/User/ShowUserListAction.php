<?php

namespace App\Actions\SystemSetting\User;

use App\Data\SimplePaginationData;
use App\Data\User\ShowUserListPagePropsData;
use App\Data\User\UserData;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询系统用户列表。
 */
class ShowUserListAction
{
    use AsAction;

    public function handle(int $page = 1, int $perPage = 10): ShowUserListPagePropsData
    {
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);

        $paginator = User::query()
            ->where('is_super_admin', false)
            ->orderBy('id')
            ->paginate($perPage, ['id', 'name', 'email', 'avatar', 'two_factor_confirmed_at'], 'page', $page);

        $users = $paginator->getCollection()
            ->map(fn (User $user) => UserData::fromModel($user))
            ->all();

        return new ShowUserListPagePropsData(
            user_list: $users,
            user_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    public function asController(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);

        return Inertia::render('admin/user/List', $this->handle($page, $perPage)->toArray());
    }
}
