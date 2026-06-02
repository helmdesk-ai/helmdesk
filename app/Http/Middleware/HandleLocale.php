<?php

namespace App\Http\Middleware;

use App\Services\Localization\LocalePreference;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * 根据用户偏好或请求设置当前语言。
 */
class HandleLocale
{
    /**
     * 按请求上下文设置应用语言后继续请求。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(LocalePreference::normalizeLaravel(
            $request->user('web')?->locale
                ?? $request->user('admin')?->locale
                ?? $request->cookie('locale')
                ?? LocalePreference::preferredBrowserLocale($request)
        ));

        return $next($request);
    }
}
