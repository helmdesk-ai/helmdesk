<?php

test('SQLite vec artifact配置匹配当前平台文件', function (): void {
    $artifactPath = config('sqlite_vec.path');
    if (! is_string($artifactPath) || $artifactPath === '') {
        test()->markTestSkipped('Current platform does not have a bundled sqlite-vec artifact.');
    }

    expect(is_file($artifactPath))->toBeTrue();

    $expectedChecksum = config('sqlite_vec.sha256');
    expect($expectedChecksum)->toBeString()
        ->and(hash_file('sha256', $artifactPath))->toBe($expectedChecksum);
});
