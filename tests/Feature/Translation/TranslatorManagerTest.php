<?php

use App\Enums\TranslationProviderType;
use App\Models\TranslationProvider;
use App\Services\Translation\Drivers\AmazonTranslateDriver;
use App\Services\Translation\Drivers\AzureTranslatorDriver;
use App\Services\Translation\Drivers\BaiduTranslateDriver;
use App\Services\Translation\Drivers\DeepLDriver;
use App\Services\Translation\Drivers\GoogleTranslateDriver;
use App\Services\Translation\Drivers\TencentCloudTranslateDriver;
use App\Services\Translation\TranslatorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->createUserWithWorkspace();
});

it('google-translate 协议返回 GoogleTranslateDriver', function () {
    $provider = TranslationProvider::factory()->create([
        'protocol' => TranslationProviderType::GoogleTranslate,
    ]);

    $manager = app(TranslatorManager::class);

    expect($manager->driverFor($provider))->toBeInstanceOf(GoogleTranslateDriver::class);
});

it('每种翻译协议都返回配置的驱动', function (TranslationProviderType $protocol, string $driverClass) {
    $provider = TranslationProvider::factory()->create([
        'protocol' => $protocol,
    ]);

    $manager = app(TranslatorManager::class);

    expect($manager->driverFor($provider))->toBeInstanceOf($driverClass);
})->with([
    'google' => [TranslationProviderType::GoogleTranslate, GoogleTranslateDriver::class],
    'deepl' => [TranslationProviderType::DeepL, DeepLDriver::class],
    'azure' => [TranslationProviderType::AzureTranslator, AzureTranslatorDriver::class],
    'baidu' => [TranslationProviderType::BaiduTranslate, BaiduTranslateDriver::class],
    'tencent' => [TranslationProviderType::TencentCloudTranslate, TencentCloudTranslateDriver::class],
    'amazon' => [TranslationProviderType::AmazonTranslate, AmazonTranslateDriver::class],
]);

it('按 provider 缓存驱动实例', function () {
    $provider = TranslationProvider::factory()->create([
    ]);

    $manager = app(TranslatorManager::class);

    $first = $manager->driverFor($provider);
    $second = $manager->driverFor($provider);

    expect($first)->toBe($second);
});

it('不同 provider 返回不同驱动实例', function () {
    $providerA = TranslationProvider::factory()->create([
        'slug' => 'google-a',
    ]);
    $providerB = TranslationProvider::factory()->create([
        'slug' => 'google-b',
    ]);

    $manager = app(TranslatorManager::class);

    expect($manager->driverFor($providerA))->not->toBe($manager->driverFor($providerB));
});
