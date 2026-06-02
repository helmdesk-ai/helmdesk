<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('总后台不再提供工作区管理路由', function () {
    expect(Route::has('admin.workspaces.index'))->toBeFalse()
        ->and(Route::has('admin.workspaces.trash'))->toBeFalse()
        ->and(Route::has('admin.workspaces.create'))->toBeFalse()
        ->and(Route::has('admin.workspaces.store'))->toBeFalse()
        ->and(Route::has('admin.workspaces.show'))->toBeFalse()
        ->and(Route::has('admin.workspaces.edit'))->toBeFalse()
        ->and(Route::has('admin.workspaces.update'))->toBeFalse()
        ->and(Route::has('admin.workspaces.destroy'))->toBeFalse()
        ->and(Route::has('admin.workspaces.restore'))->toBeFalse()
        ->and(Route::has('admin.workspaces.members.store'))->toBeFalse()
        ->and(Route::has('admin.workspaces.members.destroy'))->toBeFalse();
});
