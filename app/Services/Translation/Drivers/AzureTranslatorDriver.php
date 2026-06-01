<?php

namespace App\Services\Translation\Drivers;

use App\Services\Translation\TranslationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Microsoft Azure Translator Text API v3 driver。
 */
class AzureTranslatorDriver extends HttpTranslationDriver
{
    /**
     * 调用 Azure Translator v3 translate 接口。
     *
     * @param  array<string, mixed>  $options
     */
    public function translate(string $text, string $sourceLang, string $targetLang, array $options = []): TranslationResult
    {
        $apiKey = $this->requiredCredential('api_key');
        $endpoint = rtrim($this->credentialOrDefault('endpoint', 'https://api.cognitive.microsofttranslator.com'), '/');
        $sourceLang = $this->normalizeSourceLang($sourceLang);

        $query = [
            'api-version' => '3.0',
            'to' => $this->normalizeAzureLanguage($targetLang),
        ];

        if ($sourceLang !== 'auto') {
            $query['from'] = $this->normalizeAzureLanguage($sourceLang);
        }

        $headers = [
            'Ocp-Apim-Subscription-Key' => $apiKey,
            'X-ClientTraceId' => (string) Str::uuid(),
        ];

        $region = $this->credential('region');
        if ($region !== '') {
            $headers['Ocp-Apim-Subscription-Region'] = $region;
        }

        $startedAt = $this->nowMs();

        try {
            $response = Http::timeout($this->requestTimeout())
                ->retry(2, 200, throw: false)
                ->withHeaders($headers)
                ->post($endpoint.'/translate?'.http_build_query($query), [
                    ['Text' => $text],
                ]);
        } catch (ConnectionException $e) {
            throw $this->connectionFailed($e);
        }

        if ($response->failed()) {
            throw $this->upstreamFailed($response, $this->extractErrorMessage($response));
        }

        $body = $response->json();
        $first = is_array($body) ? ($body[0] ?? null) : null;
        $translation = is_array($first) ? ($first['translations'][0] ?? null) : null;
        if (! is_array($translation) || ! isset($translation['text'])) {
            throw $this->missingTranslationsPayload($response->status());
        }

        $detected = is_array($first['detectedLanguage'] ?? null)
            ? ($first['detectedLanguage']['language'] ?? null)
            : null;

        return new TranslationResult(
            text: (string) $translation['text'],
            source_lang: is_string($detected) ? $detected : $sourceLang,
            target_lang: $targetLang,
            provider_slug: $this->provider->slug,
            model: null,
            latency_ms: $this->latencyMs($startedAt),
            char_count: mb_strlen($text),
        );
    }

    /**
     * Azure 对中文脚本有专门代码，其他语言保留 BCP-47 小写形式。
     */
    private function normalizeAzureLanguage(string $language): string
    {
        $normalized = str_replace('_', '-', trim($language));

        return match (strtolower($normalized)) {
            'zh', 'zh-cn', 'zh-hans' => 'zh-Hans',
            'zh-tw', 'zh-hant', 'zh-hk' => 'zh-Hant',
            default => strtolower($normalized),
        };
    }

    /**
     * 从 Azure Translator 错误响应体中提取可读错误信息。
     */
    private function extractErrorMessage(Response $response): ?string
    {
        $body = $response->json();
        $message = is_array($body) ? ($body['error']['message'] ?? null) : null;

        return is_string($message) ? $message : null;
    }
}
