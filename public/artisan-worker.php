<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Console\Signals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

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
$app->make(Kernel::class)->bootstrap();
Signals::resolveAvailabilityUsing(static fn (): bool => false);

$handler = static function (array $request): array {
    if (empty($_SERVER['PHP_SELF'])) {
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_FILENAME'] = 'artisan';
        $_SERVER['argv'] = ['artisan'];
        $_SERVER['argc'] = 1;
    }

    $command = $request['command'] ?? '';
    if (empty($command)) {
        return ['output' => 'error: No command provided'];
    }

    try {
        Artisan::call($command);

        return ['output' => Artisan::output()];
    } catch (Throwable $e) {
        return ['output' => 'error: '.$e->getMessage()."\n".$e->getTraceAsString()];
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
