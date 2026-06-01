<?php

use Illuminate\Support\Facades\File;

test('后台页面不使用绿色提示词工具类', function () {
    $forbiddenPatterns = [
        'text-green-',
        'bg-green-',
        'border-green-',
        'ring-green-',
        'text-emerald-',
        'bg-emerald-',
        'border-emerald-',
        'ring-emerald-',
        'text-lime-',
        'bg-lime-',
        'text-teal-',
        'bg-teal-',
    ];

    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['vue', 'ts'], true))
        ->flatMap(function (SplFileInfo $file) use ($forbiddenPatterns): array {
            $contents = File::get($file->getPathname());

            return collect($forbiddenPatterns)
                ->filter(fn (string $pattern): bool => str_contains($contents, $pattern))
                ->map(fn (string $pattern): string => $file->getRelativePathname().': '.$pattern)
                ->all();
        })
        ->values();

    expect($violations)->toBeEmpty();
});
