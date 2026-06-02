<?php

use App\Models\SystemContext;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('单租户后台上下文使用系统基础设置作为名称', function () {
    /** @var GeneralSettings $settings */
    $settings = app(GeneralSettings::class);
    $settings->name = '单租户服务台';
    $settings->save();

    $systemContext = SystemContext::current();

    expect($systemContext->id)->toBe('single')
        ->and($systemContext->slug)->toBe('admin')
        ->and($systemContext->name)->toBe('单租户服务台')
        ->and($systemContext->exists)->toBeFalse();
});
