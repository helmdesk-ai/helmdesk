<?php

namespace App\Actions\Home;

use App\Settings\GeneralSettings;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示首页，并告诉前端当前是否允许注册。
 */
class ShowHomePageAction
{
    use AsAction;

    /**
     * 注入系统基础设置，用于控制公开首页注册入口。
     */
    public function __construct(
        private readonly GeneralSettings $settings,
    ) {}

    /**
     * 返回公开欢迎页及注册入口状态。
     */
    public function asController(): Response
    {
        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()) && $this->settings->allow_registration,
        ]);
    }
}
