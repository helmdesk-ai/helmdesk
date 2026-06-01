<?php

namespace App\Actions\Dashboard;

use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示当前工作区仪表板页面。
 */
class ShowDashboardPageAction
{
    use AsAction;

    /**
     * 返回当前工作区仪表板页面组件名。
     */
    public function handle(): string
    {
        return 'Dashboard';
    }

    /**
     * 渲染当前工作区仪表板页面。
     */
    public function asController(): Response
    {
        return Inertia::render($this->handle());
    }
}
