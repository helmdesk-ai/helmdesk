<?php

namespace App\Services\Translation\Drivers;

use App\Services\Translation\TranslationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Google Translation v2 driver。
 *
 * 选 v2 而不是 v3 的原因：v2 直接用 API Key 认证（GET ?key=xxx），不需要 OAuth 服务账号或 ADC，
 * 配置最轻，最适合作为第一个真实 driver 验证抽象层是否合理。后续要切 v3 只需要新增一个 driver 类。
 *
 * 不引入 google/cloud-translate SDK：该 SDK 会拖入 grpc / protobuf 等大依赖，对一次性 HTTP 调用是过度设计。
 */
class GoogleTranslateDriver extends HttpTranslationDriver
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    /**
     * 调用 Google Translation v2 翻译一段文本，并把响应转成统一的 TranslationResult。
     *
     * 失败路径会统一抛 TranslationProviderException，附带 HTTP 状态码方便上层判断重试 / 降级。
     *
     * @param  array<string, mixed>  $options  暂未使用，预留给后续扩展（如 model variant）
     */
    public function translate(string $text, string $sourceLang, string $targetLang, array $options = []): TranslationResult
    {
        $apiKey = $this->requiredCredential('api_key');

        $payload = [
            'q' => $text,
            'target' => $targetLang,
            'format' => 'text',
        ];
        // Google v2 接受省略 source 以触发自动识别；传入 "auto" 也按缺省处理，让 provider 自己检测。
        if ($sourceLang !== '' && $sourceLang !== 'auto') {
            $payload['source'] = $sourceLang;
        }

        $startedAt = $this->nowMs();

        try {
            $response = Http::timeout($this->requestTimeout())
                ->retry(2, 200, throw: false)
                ->asForm()
                ->post(self::ENDPOINT.'?key='.urlencode($apiKey), $payload);
        } catch (ConnectionException $e) {
            throw $this->connectionFailed($e);
        }

        if ($response->failed()) {
            throw $this->upstreamFailed($response, $this->extractErrorMessage($response->json()), new RequestException($response));
        }

        $latencyMs = $this->latencyMs($startedAt);

        $body = $response->json();
        $first = $body['data']['translations'][0] ?? null;
        if (! is_array($first) || ! isset($first['translatedText'])) {
            throw $this->missingTranslationsPayload($response->status());
        }

        // Google v2 在 format=text 模式下仍会对原文里的特殊字符做 HTML entity 编码
        // （例如 & → &amp;、' → &#39;），不解码会让客服气泡里出现 &amp; 这种"乱码"。
        $translated = html_entity_decode((string) $first['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return new TranslationResult(
            text: $translated,
            // Google 在自动检测时把检测结果回填到 detectedSourceLanguage；显式传过来的 source 则原样返回。
            source_lang: (string) ($first['detectedSourceLanguage'] ?? $sourceLang),
            target_lang: $targetLang,
            provider_slug: $this->provider->slug,
            model: null,
            latency_ms: $latencyMs,
            char_count: mb_strlen($text),
        );
    }

    /**
     * 从 Google 错误响应体里提取人类可读的 message，没有就让调用方退回到 raw body。
     *
     * @param  mixed  $body
     */
    private function extractErrorMessage($body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $message = $body['error']['message'] ?? null;

        return is_string($message) ? $message : null;
    }
}
