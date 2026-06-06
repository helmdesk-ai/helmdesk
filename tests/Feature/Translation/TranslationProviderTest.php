<?php

use App\Enums\TranslationProviderType;
use App\Models\TranslationProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->createUserWithSystem();
});

it('加密持久化凭据并转换 protocol 枚举', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'super-secret'],
    ]);

    expect($provider->protocol)->toBe(TranslationProviderType::GoogleTranslate)
        ->and($provider->credentials)->toBe(['api_key' => 'super-secret']);

    // 直接走 DB facade 拿原始字段值，绕开 Eloquent 的 encrypted:array cast，
    // 确保数据库里存的是密文而不是明文。
    $raw = DB::table('translation_providers')
        ->where('id', $provider->id)
        ->value('credentials');

    expect($raw)->toBeString()
        ->and($raw)->not->toContain('super-secret');
});

it('mergeCredentials 在输入为空时保留 secret 字段', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'existing-key'],
        'credential_fields' => [
            ['field' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true],
        ],
    ]);

    $merged = $provider->mergeCredentials(['api_key' => '']);

    expect($merged)->toBe(['api_key' => 'existing-key']);
});

it('mergeCredentials 在输入为空时清除非 secret 字段', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['region' => 'us-central1'],
        'credential_fields' => [
            ['field' => 'region', 'label' => 'Region', 'required' => false, 'secret' => false],
        ],
    ]);

    $merged = $provider->mergeCredentials(['region' => '']);

    expect($merged)->toBe([]);
});

it('强制同一系统内 slug 唯一', function () {
    TranslationProvider::factory()->create([
        'slug' => 'gtranslate',
    ]);

    expect(fn () => TranslationProvider::factory()->create([
        'slug' => 'gtranslate',
    ]))->toThrow(QueryException::class);
});
