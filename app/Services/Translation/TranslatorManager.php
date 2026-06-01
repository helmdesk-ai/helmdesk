<?php

namespace App\Services\Translation;

use App\Enums\TranslationProviderType;
use App\Models\TranslationProvider;
use App\Services\Translation\Drivers\AmazonTranslateDriver;
use App\Services\Translation\Drivers\AzureTranslatorDriver;
use App\Services\Translation\Drivers\BaiduTranslateDriver;
use App\Services\Translation\Drivers\DeepLDriver;
use App\Services\Translation\Drivers\GoogleTranslateDriver;
use App\Services\Translation\Drivers\TencentCloudTranslateDriver;
use Illuminate\Contracts\Container\Container;

/**
 * 按 TranslationProvider 解析并缓存对应的 driver 实例。
 *
 * 设计要点：
 * - 通过容器解析 driver 类，方便在测试里用 `app()->bind(GoogleTranslateDriver::class, ...)` swap 假实现；
 * - 同一 provider id 的 driver 在单次请求内复用，避免重复实例化（Octane 下也安全：Manager 自身是 scoped）；
 * - protocol → driver class 的映射在 PHP 里硬编码，因为加新协议本就需要新代码，没必要做配置化。
 */
class TranslatorManager
{
    /**
     * @var array<string, TranslatorContract>
     */
    private array $cache = [];

    /**
     * 注入容器以便用 make() 解析 driver 类，并支持测试覆盖具体实现。
     */
    public function __construct(private readonly Container $container) {}

    /**
     * 返回对应 TranslationProvider 的 driver 实例。
     *
     * 同一请求内对同一 provider 多次调用会复用同一个 driver；不同 provider 各自独立。
     * 传入 fresh: true 时跳过缓存并且不写入缓存，用于临时凭据覆盖的一次性检测。
     */
    public function driverFor(TranslationProvider $provider, bool $fresh = false): TranslatorContract
    {
        if (! $fresh && isset($this->cache[$provider->id])) {
            return $this->cache[$provider->id];
        }

        $driverClass = $this->resolveDriverClass($provider->protocol);

        /** @var TranslatorContract $driver */
        $driver = $this->container->make($driverClass, ['provider' => $provider]);

        if (! $fresh) {
            $this->cache[$provider->id] = $driver;
        }

        return $driver;
    }

    /**
     * 把枚举映射到具体 driver 的全限定类名；每新增一个 case 都需要在这里登记。
     *
     * @return class-string<TranslatorContract>
     */
    private function resolveDriverClass(TranslationProviderType $protocol): string
    {
        return match ($protocol) {
            TranslationProviderType::GoogleTranslate => GoogleTranslateDriver::class,
            TranslationProviderType::DeepL => DeepLDriver::class,
            TranslationProviderType::AzureTranslator => AzureTranslatorDriver::class,
            TranslationProviderType::BaiduTranslate => BaiduTranslateDriver::class,
            TranslationProviderType::TencentCloudTranslate => TencentCloudTranslateDriver::class,
            TranslationProviderType::AmazonTranslate => AmazonTranslateDriver::class,
        };
    }
}
