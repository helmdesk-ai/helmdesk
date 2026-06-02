<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('总后台不再提供系统管理路由', function () {
    expect(Route::has('admin.systems.index'))->toBeFalse()
        ->and(Route::has('admin.systems.trash'))->toBeFalse()
        ->and(Route::has('admin.systems.create'))->toBeFalse()
        ->and(Route::has('admin.systems.store'))->toBeFalse()
        ->and(Route::has('admin.systems.show'))->toBeFalse()
        ->and(Route::has('admin.systems.edit'))->toBeFalse()
        ->and(Route::has('admin.systems.update'))->toBeFalse()
        ->and(Route::has('admin.systems.destroy'))->toBeFalse()
        ->and(Route::has('admin.systems.restore'))->toBeFalse()
        ->and(Route::has('admin.systems.members.store'))->toBeFalse()
        ->and(Route::has('admin.systems.members.destroy'))->toBeFalse();
});
