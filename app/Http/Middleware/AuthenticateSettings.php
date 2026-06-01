<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 设置页访问校验中间件。
 */
class AuthenticateSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        $from = $request->query('from_workspace');
        $hasFromWorkspace = is_string($from) && $from !== '';

        if ($hasFromWorkspace) { // 带 from_workspace 使用 web guard
            if (! Auth::guard('web')->check()) {
                return redirect()->route('login');
            }
            Auth::shouldUse('web');
        } else { // 不带 from_workspace 使用 admin guard
            if (! Auth::guard('admin')->check()) {
                return redirect()->route('login');
            }
            Auth::shouldUse('admin');
        }

        Inertia::share('auth', ['user' => $request->user()]);

        return $next($request);
    }
}
