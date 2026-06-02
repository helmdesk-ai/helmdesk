<?php

use App\Models\TranslationProvider;
use App\Services\Translation\Drivers\GoogleTranslateDriver;
use App\Services\Translation\Exceptions\TranslationProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->createUserWithSystem();
    $this->provider = TranslationProvider::factory()->create([
        'slug' => 'google-tr-test',
        'credentials' => ['api_key' => 'fake-key'],
    ]);
});

it('翻译文本并返回填充完整的 TranslationResult', function () {
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => '你好', 'detectedSourceLanguage' => 'en'],
                ],
            ],
        ]),
    ]);

    $driver = new GoogleTranslateDriver($this->provider);
    $result = $driver->translate('Hello', 'en', 'zh-CN');

    expect($result->text)->toBe('你好')
        ->and($result->source_lang)->toBe('en')
        ->and($result->target_lang)->toBe('zh-CN')
        ->and($result->provider_slug)->toBe('google-tr-test')
        ->and($result->model)->toBeNull()
        ->and($result->char_count)->toBe(5)
        ->and($result->latency_ms)->toBeGreaterThanOrEqual(0);
});

it('sourceLang 为 auto 时省略 source 查询并使用检测到的语言', function () {
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => 'Hola', 'detectedSourceLanguage' => 'en'],
                ],
            ],
        ]),
    ]);

    $driver = new GoogleTranslateDriver($this->provider);
    $result = $driver->translate('Hello', 'auto', 'es');

    expect($result->source_lang)->toBe('en');
    Http::assertSent(function ($request) {
        $data = $request->data();

        return ! array_key_exists('source', $data) && ($data['target'] ?? null) === 'es';
    });
});

it('解码译文中的 HTML 实体', function () {
    // Google v2 即使在 format=text 模式下也会对原文里的特殊字符做 HTML entity 编码，
    // driver 必须解码后再返回，否则客服端会看到 "Tom &amp; Jerry" 这种乱码。
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => 'Tom &amp; Jerry &#39;reloaded&#39;', 'detectedSourceLanguage' => 'en'],
                ],
            ],
        ]),
    ]);

    $driver = new GoogleTranslateDriver($this->provider);
    $result = $driver->translate('Tom & Jerry \'reloaded\'', 'en', 'zh-CN');

    expect($result->text)->toBe("Tom & Jerry 'reloaded'");
});

it('4xx 响应时抛出带状态码的 TranslationProviderException', function () {
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key not valid'],
        ], 400),
    ]);

    $driver = new GoogleTranslateDriver($this->provider);

    try {
        $driver->translate('Hello', 'en', 'zh-CN');
        $this->fail('Expected TranslationProviderException was not thrown.');
    } catch (TranslationProviderException $e) {
        expect($e->statusCode)->toBe(400)
            ->and($e->providerSlug)->toBe('google-tr-test')
            ->and($e->getMessage())->toContain('API key not valid');
    }
});

it('凭据缺失时抛出异常', function () {
    $this->provider->update(['credentials' => []]);

    $driver = new GoogleTranslateDriver($this->provider->fresh());

    expect(fn () => $driver->translate('Hello', 'en', 'zh-CN'))
        ->toThrow(TranslationProviderException::class, 'missing api_key');
});

it('响应缺少 translations payload 时抛出异常', function () {
    Http::fake([
        'translation.googleapis.com/*' => Http::response(['data' => []]),
    ]);

    $driver = new GoogleTranslateDriver($this->provider);

    expect(fn () => $driver->translate('Hello', 'en', 'zh-CN'))
        ->toThrow(TranslationProviderException::class, 'missing translations payload');
});

it('将 ConnectionException 包装为 TranslationProviderException 且不泄露状态码', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $driver = new GoogleTranslateDriver($this->provider);

    try {
        $driver->translate('Hello', 'en', 'zh-CN');
        $this->fail('Expected TranslationProviderException was not thrown.');
    } catch (TranslationProviderException $e) {
        expect($e->statusCode)->toBeNull()
            ->and($e->providerSlug)->toBe('google-tr-test')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('容忍 null 凭据且不会触发 PHP warning', function () {
    $this->provider->update(['credentials' => null]);

    $driver = new GoogleTranslateDriver($this->provider->fresh());

    expect(fn () => $driver->translate('Hello', 'en', 'zh-CN'))
        ->toThrow(TranslationProviderException::class, 'missing api_key');
});
