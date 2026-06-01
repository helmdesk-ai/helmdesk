<?php

use App\Services\SystemSetting\SystemBaseUrl;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('配置了真实 base_url 时返回该值并去掉尾部斜杠', function () {
    config(['app.url' => 'https://env.example.test']);

    $settings = app(GeneralSettings::class);
    $settings->base_url = 'https://support.example.test/';
    $settings->save();

    expect(app(SystemBaseUrl::class)->value())->toBe('https://support.example.test');
});

test('base_url 仍是占位默认值时回落到环境推断的 app.url', function () {
    config(['app.url' => 'https://env.example.test']);

    $settings = app(GeneralSettings::class);
    $settings->base_url = GeneralSettings::DEFAULT_BASE_URL;
    $settings->save();

    expect(app(SystemBaseUrl::class)->value())->toBe('https://env.example.test');
});

test('base_url 为空时回落到环境推断的 app.url', function () {
    config(['app.url' => 'https://env.example.test/']);

    $settings = app(GeneralSettings::class);
    $settings->base_url = '';
    $settings->save();

    expect(app(SystemBaseUrl::class)->value())->toBe('https://env.example.test');
});
