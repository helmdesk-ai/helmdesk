<?php

// queue-worker.php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Console\Signals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\WorkerOptions;

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
Signals::resolveAvailabilityUsing(static fn (): bool => false);

$handler = static function (array $request): array {
    $connection = $request['connection'] ?? null;
    $queue = $request['queue'] ?? null;

    $result = ['processed' => false, 'job' => null, 'error' => null];

    try {
        $worker = app('queue.worker');
        $queueManager = app('queue');

        // 如果没有指定连接，使用配置的默认连接
        if ($connection === null) {
            $connection = config('queue.default');
        }

        // 获取队列连接
        $connectionInstance = $queueManager->connection($connection);

        // 如果没有指定队列，使用默认队列
        if ($queue === null) {
            $queue = 'default';
        }

        // 尝试获取并处理一个任务
        $job = $connectionInstance->pop($queue);

        if ($job) {
            // 处理任务
            $worker->process($connection, $job, new WorkerOptions(
                $delay = 0,
                $memory = 128,
                $timeout = 60,
                $sleep = 3,
                $maxTries = 1,
                $force = false,
                $stopWhenEmpty = false
            ));

            $result['processed'] = true;
            $result['job'] = [
                'id' => method_exists($job, 'getJobId') ? $job->getJobId() : null,
                'name' => $job->getName(),
            ];
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        $result['trace'] = $e->getTraceAsString();
    }

    return $result;
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);
for ($nbRequests = 0; ! $maxRequests || $nbRequests < $maxRequests; $nbRequests++) {
    $keepRunning = \frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (! $keepRunning) {
        break;
    }
}
