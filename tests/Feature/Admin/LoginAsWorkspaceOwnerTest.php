<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('总后台不再提供代入工作区所有者入口', function () {
    expect(Route::has('admin.workspaces.login-as-owner'))->toBeFalse();
});
