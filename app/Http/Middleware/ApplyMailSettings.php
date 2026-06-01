<?php

namespace App\Http\Middleware;

use App\Services\Mail\ApplyMailSettings as MailSettingsApplier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 每个请求开始时刷新后台邮件配置，兼容 Octane 等长驻进程。
 */
class ApplyMailSettings
{
    /**
     * 在请求进入应用前刷新运行时邮件配置。
     */
    public function handle(Request $request, Closure $next): Response
    {
        app(MailSettingsApplier::class)->apply();

        return $next($request);
    }
}
