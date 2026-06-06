<?php

use App\Services\Localization\LocalePreference;

test('locale 匹配支持大小写下划线和同语言区域变体', function (): void {
    expect(LocalePreference::matches('zh_CN', 'zh-CN'))->toBeTrue()
        ->and(LocalePreference::matches('EN-us', 'en'))->toBeTrue()
        ->and(LocalePreference::matches('ja-JP', 'ja'))->toBeTrue();
});

test('locale 匹配不会把空值或不同语言视为相同', function (): void {
    expect(LocalePreference::matches('', 'zh-CN'))->toBeFalse()
        ->and(LocalePreference::matches('  ', 'en'))->toBeFalse()
        ->and(LocalePreference::matches('zh-CN', 'en'))->toBeFalse();
});
