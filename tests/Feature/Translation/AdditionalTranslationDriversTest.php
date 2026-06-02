<?php

use App\Enums\TranslationProviderType;
use App\Models\TranslationProvider;
use App\Services\Translation\Drivers\AmazonTranslateDriver;
use App\Services\Translation\Drivers\AzureTranslatorDriver;
use App\Services\Translation\Drivers\BaiduTranslateDriver;
use App\Services\Translation\Drivers\DeepLDriver;
use App\Services\Translation\Drivers\TencentCloudTranslateDriver;
use App\Services\Translation\Exceptions\TranslationProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->createUserWithSystem();
});

function translationProviderFor(TranslationProviderType $protocol, string $slug, array $credentials): TranslationProvider
{
    return TranslationProvider::factory()->create([
        'slug' => $slug,
        'name' => $protocol->label(),
        'protocol' => $protocol,
        'credentials' => $credentials,
    ]);
}

it('使用 DeepL 翻译文本', function () {
    $provider = translationProviderFor(TranslationProviderType::DeepL, 'deepl-test', [
        'auth_key' => 'deepl-key',
    ]);

    Http::fake([
        'api.deepl.com/*' => Http::response([
            'translations' => [
                ['detected_source_language' => 'EN', 'text' => '你好'],
            ],
        ]),
    ]);

    $result = (new DeepLDriver($provider))->translate('Hello', 'auto', 'zh-CN');

    expect($result->text)->toBe('你好')
        ->and($result->source_lang)->toBe('EN')
        ->and($result->target_lang)->toBe('zh-CN')
        ->and($result->provider_slug)->toBe('deepl-test');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->hasHeader('Authorization', 'DeepL-Auth-Key deepl-key')
            && ($data['target_lang'] ?? null) === 'ZH'
            && ! array_key_exists('source_lang', $data);
    });
});

it('使用 Azure Translator 翻译文本', function () {
    $provider = translationProviderFor(TranslationProviderType::AzureTranslator, 'azure-test', [
        'api_key' => 'azure-key',
        'region' => 'eastus',
    ]);

    Http::fake([
        'api.cognitive.microsofttranslator.com/*' => Http::response([
            [
                'detectedLanguage' => ['language' => 'en'],
                'translations' => [
                    ['text' => '你好', 'to' => 'zh-Hans'],
                ],
            ],
        ]),
    ]);

    $result = (new AzureTranslatorDriver($provider))->translate('Hello', 'auto', 'zh-CN');

    expect($result->text)->toBe('你好')
        ->and($result->source_lang)->toBe('en')
        ->and($result->target_lang)->toBe('zh-CN');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return str_contains($request->url(), 'to=zh-Hans')
            && ! str_contains($request->url(), 'from=')
            && $request->hasHeader('Ocp-Apim-Subscription-Key', 'azure-key')
            && $request->hasHeader('Ocp-Apim-Subscription-Region', 'eastus')
            && ($body[0]['Text'] ?? null) === 'Hello';
    });
});

it('使用 Baidu Translate 翻译文本', function () {
    $provider = translationProviderFor(TranslationProviderType::BaiduTranslate, 'baidu-test', [
        'app_id' => 'baidu-app',
        'app_secret' => 'baidu-secret',
    ]);

    Http::fake([
        'fanyi-api.baidu.com/*' => Http::response([
            'from' => 'en',
            'to' => 'zh',
            'trans_result' => [
                ['src' => 'Hello', 'dst' => '你好'],
            ],
        ]),
    ]);

    $result = (new BaiduTranslateDriver($provider))->translate('Hello', 'en', 'zh-CN');

    expect($result->text)->toBe('你好')
        ->and($result->source_lang)->toBe('en')
        ->and($result->target_lang)->toBe('zh-CN');

    Http::assertSent(function (Request $request) {
        $data = $request->data();
        $expectedSign = md5('baidu-app'.'Hello'.$data['salt'].'baidu-secret');

        return ($data['appid'] ?? null) === 'baidu-app'
            && ($data['from'] ?? null) === 'en'
            && ($data['to'] ?? null) === 'zh'
            && ($data['sign'] ?? null) === $expectedSign;
    });
});

it('使用 Tencent Cloud Machine Translation 翻译文本', function () {
    $provider = translationProviderFor(TranslationProviderType::TencentCloudTranslate, 'tencent-test', [
        'secret_id' => 'tencent-id',
        'secret_key' => 'tencent-key',
        'region' => 'ap-guangzhou',
    ]);

    Http::fake([
        'tmt.tencentcloudapi.com*' => Http::response([
            'Response' => [
                'Source' => 'en',
                'Target' => 'zh',
                'TargetText' => '你好',
                'RequestId' => 'request-id',
            ],
        ]),
    ]);

    $result = (new TencentCloudTranslateDriver($provider))->translate('Hello', 'en', 'zh-CN');

    expect($result->text)->toBe('你好')
        ->and($result->source_lang)->toBe('en')
        ->and($result->target_lang)->toBe('zh-CN');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-TC-Action', 'TextTranslate')
            && $request->hasHeader('X-TC-Version', '2018-03-21')
            && str_starts_with((string) $request->header('Authorization')[0], 'TC3-HMAC-SHA256 Credential=tencent-id/')
            && ($body['SourceText'] ?? null) === 'Hello'
            && ($body['Source'] ?? null) === 'en'
            && ($body['Target'] ?? null) === 'zh';
    });
});

it('使用 Amazon Translate 翻译文本', function () {
    $provider = translationProviderFor(TranslationProviderType::AmazonTranslate, 'amazon-test', [
        'access_key_id' => 'aws-access',
        'secret_access_key' => 'aws-secret',
        'region' => 'us-east-1',
    ]);

    Http::fake([
        'translate.us-east-1.amazonaws.com*' => Http::response([
            'TranslatedText' => '你好',
            'SourceLanguageCode' => 'en',
            'TargetLanguageCode' => 'zh',
        ]),
    ]);

    $result = (new AmazonTranslateDriver($provider))->translate('Hello', 'en', 'zh-CN');

    expect($result->text)->toBe('你好')
        ->and($result->source_lang)->toBe('en')
        ->and($result->target_lang)->toBe('zh-CN');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Amz-Target', 'AWSShineFrontendService_20170701.TranslateText')
            && str_starts_with((string) $request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=aws-access/')
            && ($body['Text'] ?? null) === 'Hello'
            && ($body['SourceLanguageCode'] ?? null) === 'en'
            && ($body['TargetLanguageCode'] ?? null) === 'zh';
    });
});

it('使用 session token 凭据签名 Amazon Translate 请求', function () {
    $provider = translationProviderFor(TranslationProviderType::AmazonTranslate, 'amazon-session-test', [
        'access_key_id' => 'aws-access',
        'secret_access_key' => 'aws-secret',
        'session_token' => 'aws-session-token',
        'region' => 'us-east-1',
    ]);

    Http::fake([
        'translate.us-east-1.amazonaws.com*' => Http::response([
            'TranslatedText' => '你好',
            'SourceLanguageCode' => 'en',
            'TargetLanguageCode' => 'zh',
        ]),
    ]);

    (new AmazonTranslateDriver($provider))->translate('Hello', 'en', 'zh-CN');

    Http::assertSent(function (Request $request) {
        $authorization = (string) $request->header('Authorization')[0];

        return $request->hasHeader('X-Amz-Security-Token', 'aws-session-token')
            && str_contains($authorization, 'SignedHeaders=content-type;host;x-amz-date;x-amz-security-token;x-amz-target');
    });
});

it('附加翻译驱动会包装上游错误', function () {
    $provider = translationProviderFor(TranslationProviderType::DeepL, 'deepl-error-test', [
        'auth_key' => 'deepl-key',
    ]);

    Http::fake([
        'api.deepl.com/*' => Http::response(['message' => 'Authorization failed'], 403),
    ]);

    expect(fn () => (new DeepLDriver($provider))->translate('Hello', 'en', 'zh-CN'))
        ->toThrow(TranslationProviderException::class, 'Authorization failed');
});
