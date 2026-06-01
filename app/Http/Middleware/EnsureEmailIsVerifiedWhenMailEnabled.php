<?php

namespace App\Http\Middleware;

use App\Settings\MailSettings;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仅在系统邮件启用时要求普通用户完成邮箱验证。
 */
class EnsureEmailIsVerifiedWhenMailEnabled
{
    /**
     * 注入邮件设置和 Laravel 官方邮箱验证中间件。
     */
    public function __construct(
        private readonly MailSettings $mailSettings,
        private readonly EnsureEmailIsVerified $ensureEmailIsVerified,
    ) {}

    /**
     * 根据邮件开关决定是否执行邮箱验证校验。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        $this->mailSettings->refresh();

        if (! $this->mailSettings->enabled) {
            return $next($request);
        }

        return $this->ensureEmailIsVerified->handle($request, $next, $redirectToRoute);
    }
}
