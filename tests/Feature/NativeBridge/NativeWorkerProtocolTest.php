<?php

use Symfony\Component\Process\Process;

test('原生工作器映射验证异常到422桥接错误', function (): void {
    $script = <<<'PHP'
function frankenphp_handle_request($handler) {
    $response = $handler([
        'class' => 'App\\Actions\\Native\\Reception\\StartOrResumeReceptionSessionBridgeAction',
        'params' => ['wch_test', null, 'invalid_entry_mode'],
    ]);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

    return false;
}

require 'public/native-worker.php';
PHP;

    $process = new Process([PHP_BINARY, '-r', $script], base_path());
    $process->mustRun();

    $payload = json_decode($process->getOutput(), true);

    expect($payload)
        ->toHaveKey('error')
        ->and($payload['error']['exception'])->toBe('ValidationException')
        ->and($payload['error']['status_code'])->toBe(422);
});
