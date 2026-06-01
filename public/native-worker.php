<?php

// native-worker.php
//
// Go 端通过 FrankenPHP worker 同步调用 Native Bridge Action，协议：
//   请求:  {"class": "App\\Actions\\...\\XxxAction", "params": [...]}
//   成功:  {"data": <mixed>}
//   失败:  {"error": {"message": string, "status_code": int, "exception": string}}
//
// 约定：
// - 只允许调用 App\Actions\Native\ 下的 Bridge Action。
// - 一律通过 Action 的静态 ::run() 调用 handle()，符合 lorisleiva/laravel-actions 的地道用法。
// - Bridge Action 负责把跨语言的小类型协议转换为业务 Action 需要的 PHP 类型。
// - 业务异常（NotFoundHttpException / ValidationException 等 HttpExceptionInterface）的 HTTP 语义
//   会自动透传给 Go 端，Go handler 可据此设置响应状态码，无需每个 Action 自造 envelope。
// - 返回 Spatie\LaravelData\Data / DataCollection 会自动 ->toArray()。

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Spatie\LaravelData\Contracts\TransformableData;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (! defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}
if (! defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}
if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$normalize = static function (mixed $value): mixed {
    if ($value instanceof TransformableData) {
        return $value->toArray();
    }

    return $value;
};

$handler = static function (array $request) use ($normalize): array {
    $class = $request['class'] ?? '';
    $params = $request['params'] ?? [];
    $nativeActionPrefix = 'App\\Actions\\Native\\';

    if (! is_string($class) || $class === '') {
        return [
            'error' => [
                'message' => 'Missing class parameter',
                'status_code' => 500,
                'exception' => 'BridgeProtocolError',
            ],
        ];
    }

    if (! str_starts_with($class, $nativeActionPrefix)) {
        return [
            'error' => [
                'message' => "Action is not exposed to native bridge: {$class}",
                'status_code' => 500,
                'exception' => 'BridgeProtocolError',
            ],
        ];
    }

    if (! class_exists($class)) {
        return [
            'error' => [
                'message' => "Action class not found: {$class}",
                'status_code' => 500,
                'exception' => 'BridgeProtocolError',
            ],
        ];
    }

    if (! method_exists($class, 'run')) {
        return [
            'error' => [
                'message' => "Action does not use AsAction trait: {$class}",
                'status_code' => 500,
                'exception' => 'BridgeProtocolError',
            ],
        ];
    }

    try {
        $result = $class::run(...(array) $params);

        return ['data' => $normalize($result)];
    } catch (HttpExceptionInterface $e) {
        return [
            'error' => [
                'message' => $e->getMessage() !== '' ? $e->getMessage() : class_basename($e),
                'status_code' => $e->getStatusCode(),
                'exception' => class_basename($e),
            ],
        ];
    } catch (ValidationException $e) {
        return [
            'error' => [
                'message' => $e->getMessage() !== '' ? $e->getMessage() : class_basename($e),
                'status_code' => HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
                'exception' => class_basename($e),
            ],
        ];
    } catch (AuthorizationException $e) {
        return [
            'error' => [
                'message' => $e->getMessage() !== '' ? $e->getMessage() : class_basename($e),
                'status_code' => $e->status() ?? HttpResponse::HTTP_FORBIDDEN,
                'exception' => class_basename($e),
            ],
        ];
    } catch (Throwable $e) {
        app()->make(LoggerInterface::class)->error($e->getMessage(), ['exception' => $e]);

        return [
            'error' => [
                'message' => $e->getMessage() !== '' ? $e->getMessage() : class_basename($e),
                'status_code' => 500,
                'exception' => class_basename($e),
            ],
        ];
    }
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);
for ($nbRequests = 0; ! $maxRequests || $nbRequests < $maxRequests; $nbRequests++) {
    $keepRunning = \frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (! $keepRunning) {
        break;
    }
}
