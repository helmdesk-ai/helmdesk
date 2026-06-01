<?php

namespace App\Actions\SystemSetting\User;

use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示系统用户创建页面。
 */
class ShowCreateUserPageAction
{
    use AsAction;

    public function asController()
    {
        return Inertia::render('admin/user/Create');
    }
}
