<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\ApplyMailSettings;
use App\Http\Middleware\AssignRequestContext;
use App\Http\Middleware\AutoFillGeneralBaseUrl;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleLocale;
use App\Services\Reception\ReceptionSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 最先注入请求关联 ID，保证后续中间件与业务里的日志都带 request_id / trace_id。
        $middleware->prepend(AssignRequestContext::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale', ReceptionSession::COOKIE_PREFIX.'*']);
        $middleware->validateCsrfTokens(except: ['api/visitor/attachments/*']);

        $middleware->web(append: [
            AutoFillGeneralBaseUrl::class,
            ApplyMailSettings::class,
            HandleAppearance::class,
            HandleLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            ApplyMailSettings::class,
            HandleLocale::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // 处理业务异常
            if ($exception instanceof BusinessException) {
                if ($request->header('X-Inertia')) {
                    return back()->withErrors(['toast' => $exception->getMessage()]);
                } else {
                    return response()->json(['message' => $exception->getMessage()], 422);
                }
            }

            // 处理验证异常 - 优化 API 的异常响应格式
            if ($exception instanceof ValidationException) {
                if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                    $errors = $exception->errors();
                    $firstField = array_key_first($errors);
                    $firstError = $errors[$firstField][0];

                    return response()->json([
                        'message' => $firstField.' '.$firstError,
                        'errors' => $errors,
                    ], 422)->setEncodingOptions(JSON_UNESCAPED_UNICODE);
                }
            }

            return $response;
        });
    })->create()
    ->useStoragePath(env('LARAVEL_STORAGE_PATH', base_path('storage')));
