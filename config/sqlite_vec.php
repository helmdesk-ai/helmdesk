<?php

// sqlite-vec 向量扩展的平台相关路径与校验配置。

$root = base_path('bootstrap/sqlite_vec');

$platform = match (PHP_OS_FAMILY) {
    'Linux' => 'linux',
    'Darwin' => 'macos',
    'Windows' => 'windows',
    default => null,
};

$architecture = match (strtolower((string) php_uname('m'))) {
    'aarch64', 'arm64' => 'arm64',
    'x86_64', 'amd64' => 'x64',
    default => null,
};

$artifacts = [
    'linux-arm64' => [
        'path' => $root.'/linux-arm64/vec0.so',
        'sha256' => '0b84cbd06418ca3040827deddd650539be05be0f657952426b926c8606217437',
    ],
    'linux-x64' => [
        'path' => $root.'/linux-x64/vec0.so',
        'sha256' => '5923730861b86c707cca5602b5f91092f9e52a46706dbc6e269fd4bb9c4498e8',
    ],
    'macos-arm64' => [
        'path' => $root.'/macos-arm64/vec0.dylib',
        'sha256' => '193e480c50b59a55977d166f4aaf0e1bc8832d6963516e5950f39e4d2ce0b793',
    ],
    'windows-x64' => [
        'path' => $root.'/windows-x64/vec0.dll',
        'sha256' => 'fcf98662a7ad9dce394b96a88f91032047823831b951c76636787c312a6476e6',
    ],
];

$artifactKey = $platform !== null && $architecture !== null
    ? sprintf('%s-%s', $platform, $architecture)
    : null;

$artifact = $artifactKey !== null ? ($artifacts[$artifactKey] ?? null) : null;

return [
    'version' => 'v0.1.9',

    'path' => $artifact['path'] ?? null,

    'sha256' => $artifact['sha256'] ?? null,
];
