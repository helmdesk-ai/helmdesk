<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 记录用户最近访问的工作区。
 */
class TrackLastWorkspace
{
    /**
     * 记录路由中的当前工作区并继续请求。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 如果用户已登录且当前路由包含 workspace_path，保存到 session
        if ($request->user() && $request->route('slug')) {
            $workspaceSlug = $request->route('slug');
            $request->session()->put('last_workspace_slug', $workspaceSlug);
        }

        return $next($request);
    }
}
