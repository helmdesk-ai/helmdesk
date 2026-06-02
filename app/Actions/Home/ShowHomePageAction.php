<?php

namespace App\Actions\Home;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示首页。
 */
class ShowHomePageAction
{
    use AsAction;

    /**
     * 返回公开欢迎页。
     */
    public function asController(): Response
    {
        return Inertia::render('Welcome', [
            'canRegister' => User::query()->doesntExist(),
        ]);
    }
}
