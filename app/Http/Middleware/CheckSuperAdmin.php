<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 限制后台管理入口仅超级管理员访问。
 */
class CheckSuperAdmin
{
    /**
     * 校验当前请求是否来自超级管理员。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_super_admin) {
            return $next($request);
        }

        abort(403, '无权限访问');
    }
}
