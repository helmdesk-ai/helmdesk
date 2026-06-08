<?php

namespace App\Services\Translation;

use App\Models\TranslationProvider;
use App\Services\Translation\Exceptions\TranslationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 全局翻译供应商轮询池。
 *
 * 运行时不再由接待方案指定具体供应商：从「已启用且凭据完整」的全局供应商里随机取一个执行翻译，
 * 失败则按打散顺序轮询下一个，直到成功或全部失败。固定话术按内容 + 目标语言缓存，避免重复请求。
 */
class TranslationProviderPool
{
    /**
     * 注入 driver 管理器，按 provider 协议解析具体 driver。
     */
    public function __construct(
        private readonly TranslatorManager $manager,
    ) {}

    /**
     * 返回可用供应商（已启用且凭据完整），随机打散后用于轮询。
     *
     * @return Collection<int, TranslationProvider>
     */
    public function usableProviders(): Collection
    {
        return TranslationProvider::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (TranslationProvider $provider): bool => $provider->hasCompleteCredentials())
            ->shuffle()
            ->values();
    }

    /**
     * 判断当前是否存在可用于运行时翻译的供应商。
     */
    public function hasUsable(): bool
    {
        return TranslationProvider::query()
            ->where('is_active', true)
            ->get()
            ->contains(fn (TranslationProvider $provider): bool => $provider->hasCompleteCredentials());
    }

    /**
     * 翻译一段文本：命中缓存直接返回，否则在可用供应商池里随机取用、失败轮询下一个。
     *
     * 缓存键带池指纹（所有可用供应商 id + updated_at），供应商或凭据变更即自动失效。
     */
    public function translate(string $content, string $sourceLang, string $targetLang): TranslationResult
    {
        $providers = $this->usableProviders();

        if ($providers->isEmpty()) {
            throw new TranslationException(__('translation.driver_errors.no_default_provider'));
        }

        $cacheKey = 'message_translation:'.sha1((string) json_encode([
            'pool' => $this->fingerprint($providers),
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'content' => $content,
        ], JSON_THROW_ON_ERROR));

        $payload = Cache::remember($cacheKey, now()->addDays(30), function () use ($providers, $content, $sourceLang, $targetLang): array {
            $result = $this->translateRotating($providers, $content, $sourceLang, $targetLang);

            return [
                'text' => $result->text,
                'source_lang' => $result->source_lang,
                'target_lang' => $result->target_lang,
                'provider_slug' => $result->provider_slug,
                'model' => $result->model,
                'latency_ms' => $result->latency_ms,
                'char_count' => $result->char_count,
            ];
        });

        return TranslationResult::from($payload);
    }

    /**
     * 依次尝试池中供应商，第一个成功即返回；全部失败抛出最后一次异常。
     *
     * @param  Collection<int, TranslationProvider>  $providers
     */
    private function translateRotating(Collection $providers, string $content, string $sourceLang, string $targetLang): TranslationResult
    {
        $lastException = null;

        foreach ($providers as $provider) {
            try {
                return $this->manager->driverFor($provider)->translate($content, $sourceLang, $targetLang);
            } catch (TranslationException $e) {
                $lastException = $e;
                Log::warning('翻译供应商失败，轮询下一个', [
                    'provider_id' => $provider->id,
                    'provider_slug' => $provider->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new TranslationException(__('translation.driver_errors.no_default_provider'));
    }

    /**
     * 池指纹：所有可用供应商 id + updated_at 排序，保证缓存键与打散顺序无关、随池变更失效。
     *
     * @param  Collection<int, TranslationProvider>  $providers
     * @return list<string>
     */
    private function fingerprint(Collection $providers): array
    {
        return $providers
            ->map(fn (TranslationProvider $provider): string => $provider->id.':'.($provider->updated_at?->timestamp ?? 0))
            ->sort()
            ->values()
            ->all();
    }
}
