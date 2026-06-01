<?php

namespace App\Actions\SystemSetting\User;

use App\Data\User\ShowEditUserFormData;
use App\Models\User;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示系统用户编辑页面。
 */
class ShowEditUserPageAction
{
    use AsAction;

    public function handle(string $id): ShowEditUserFormData
    {
        $user = User::query()
            ->where('is_super_admin', false)
            ->findOrFail($id);

        return ShowEditUserFormData::fromModel($user);
    }

    public function asController(string $id)
    {
        return Inertia::render('admin/user/Edit', $this->handle($id)->toArray());
    }
}
