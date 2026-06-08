<?php

use App\Models\TranslationProvider;
use App\Services\Translation\Exceptions\TranslationException;
use App\Services\Translation\TranslationProviderPool;
use App\Services\Translation\TranslationResult;
use App\Services\Translation\TranslatorContract;
use App\Services\Translation\TranslatorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    Cache::flush();
    $this->createUserWithSystem();
});

/**
 * 构造返回固定 fake driver 的 TranslatorManager mock。
 */
function poolManagerReturning(TranslatorContract $driver): TranslatorManager
{
    $manager = Mockery::mock(TranslatorManager::class);
    $manager->shouldReceive('driverFor')->andReturn($driver);

    return $manager;
}

/**
 * 永远成功的 fake driver，按调用次数计数。
 */
function poolSucceedingDriver(string $text = '你好'): TranslatorContract
{
    return new class($text) implements TranslatorContract
    {
        public int $calls = 0;

        public function __construct(private readonly string $text) {}

        public function translate(string $content, string $sourceLang, string $targetLang, array $options = []): TranslationResult
        {
            $this->calls++;

            return new TranslationResult(
                text: $this->text,
                source_lang: $sourceLang === 'auto' ? 'en' : $sourceLang,
                target_lang: $targetLang,
                provider_slug: 'fake-provider',
                model: null,
                latency_ms: 1,
                char_count: mb_strlen($content),
            );
        }
    };
}

it('usableProviders 只取已启用且凭据完整的供应商', function () {
    $active = TranslationProvider::factory()->create(['is_active' => true, 'credentials' => ['api_key' => 'k']]);
    TranslationProvider::factory()->create(['is_active' => false, 'credentials' => ['api_key' => 'k']]);
    TranslationProvider::factory()->create(['is_active' => true, 'credentials' => []]);

    $pool = new TranslationProviderPool(poolManagerReturning(poolSucceedingDriver()));

    $usable = $pool->usableProviders();

    expect($usable)->toHaveCount(1)
        ->and($usable->first()->id)->toBe($active->id)
        ->and($pool->hasUsable())->toBeTrue();
});

it('没有可用供应商时 hasUsable 为 false 且翻译抛出降级异常', function () {
    TranslationProvider::factory()->create(['is_active' => false, 'credentials' => ['api_key' => 'k']]);

    $pool = new TranslationProviderPool(poolManagerReturning(poolSucceedingDriver()));

    expect($pool->hasUsable())->toBeFalse();
    expect(fn () => $pool->translate('Hello', 'auto', 'zh-CN'))
        ->toThrow(TranslationException::class);
});

it('首个供应商失败时轮询下一个直到成功', function () {
    TranslationProvider::factory()->count(2)->create(['is_active' => true, 'credentials' => ['api_key' => 'k']]);

    // 第一次调用抛异常、第二次成功，验证池会轮询到下一个供应商。
    $driver = new class implements TranslatorContract
    {
        public int $calls = 0;

        public function translate(string $content, string $sourceLang, string $targetLang, array $options = []): TranslationResult
        {
            $this->calls++;

            if ($this->calls === 1) {
                throw new TranslationException('first provider down');
            }

            return new TranslationResult(
                text: '你好',
                source_lang: 'en',
                target_lang: $targetLang,
                provider_slug: 'fake-provider',
                model: null,
                latency_ms: 1,
                char_count: mb_strlen($content),
            );
        }
    };

    $pool = new TranslationProviderPool(poolManagerReturning($driver));

    $result = $pool->translate('Hello', 'auto', 'zh-CN');

    expect($result->text)->toBe('你好')
        ->and($driver->calls)->toBe(2);
});

it('所有供应商都失败时抛出最后一次异常', function () {
    TranslationProvider::factory()->count(2)->create(['is_active' => true, 'credentials' => ['api_key' => 'k']]);

    $driver = new class implements TranslatorContract
    {
        public function translate(string $content, string $sourceLang, string $targetLang, array $options = []): TranslationResult
        {
            throw new TranslationException('all down');
        }
    };

    $pool = new TranslationProviderPool(poolManagerReturning($driver));

    expect(fn () => $pool->translate('Hello', 'auto', 'zh-CN'))
        ->toThrow(TranslationException::class, 'all down');
});

it('相同内容和目标语言命中缓存只翻译一次', function () {
    TranslationProvider::factory()->create(['is_active' => true, 'credentials' => ['api_key' => 'k']]);

    $driver = poolSucceedingDriver();
    $pool = new TranslationProviderPool(poolManagerReturning($driver));

    $pool->translate('Hello', 'auto', 'zh-CN');
    $pool->translate('Hello', 'auto', 'zh-CN');

    expect($driver->calls)->toBe(1);
});
