<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('总后台不再提供用户管理路由', function () {
    expect(Route::has('admin.users.index'))->toBeFalse()
        ->and(Route::has('admin.users.create'))->toBeFalse()
        ->and(Route::has('admin.users.store'))->toBeFalse()
        ->and(Route::has('admin.users.edit'))->toBeFalse()
        ->and(Route::has('admin.users.update'))->toBeFalse()
        ->and(Route::has('admin.users.two-factor.reset'))->toBeFalse();
});
