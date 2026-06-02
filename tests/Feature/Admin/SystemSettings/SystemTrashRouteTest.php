<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('总后台不再提供系统回收站路由', function () {
    expect(Route::has('admin.systems.trash'))->toBeFalse()
        ->and(Route::has('admin.systems.restore'))->toBeFalse();
});
