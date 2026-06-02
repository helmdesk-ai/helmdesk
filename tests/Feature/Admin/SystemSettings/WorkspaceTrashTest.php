<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('总后台不再提供工作区回收站路由', function () {
    expect(Route::has('admin.workspaces.trash'))->toBeFalse()
        ->and(Route::has('admin.workspaces.restore'))->toBeFalse();
});
