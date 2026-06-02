<?php

use App\Models\Workspace;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('单租户后台上下文使用系统基础设置作为名称', function () {
    /** @var GeneralSettings $settings */
    $settings = app(GeneralSettings::class);
    $settings->name = '单租户服务台';
    $settings->save();

    $workspace = Workspace::current();

    expect($workspace->id)->toBe('single')
        ->and($workspace->slug)->toBe('admin')
        ->and($workspace->name)->toBe('单租户服务台')
        ->and($workspace->exists)->toBeFalse();
});
