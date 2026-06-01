<?php

namespace App\Services\Translation\Drivers;

use App\Services\Translation\TranslationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * DeepL API driver。
 */
class DeepLDriver extends HttpTranslationDriver
{
    /**
     * 调用 DeepL /v2/translate 翻译文本。
     *
     * @param  array<string, mixed>  $options
     */
    public function translate(string $text, string $sourceLang, string $targetLang, array $options = []): TranslationResult
    {
        $authKey = $this->requiredCredential('auth_key');
        $endpoint = rtrim($this->credentialOrDefault('endpoint', 'https://api.deepl.com'), '/').'/v2/translate';

        $payload = [
            'text' => $text,
            'target_lang' => $this->normalizeDeepLLanguage($targetLang, target: true),
        ];

        $sourceLang = $this->normalizeSourceLang($sourceLang);
        if ($sourceLang !== 'auto') {
            $payload['source_lang'] = $this->normalizeDeepLLanguage($sourceLang, target: false);
        }

        if (isset($options['formality']) && is_string($options['formality']) && $options['formality'] !== '') {
            $payload['formality'] = $options['formality'];
        }

        if (isset($options['glossary_id']) && is_string($options['glossary_id']) && $options['glossary_id'] !== '') {
            $payload['glossary_id'] = $options['glossary_id'];
        }

        $startedAt = $this->nowMs();

        try {
            $response = Http::timeout($this->requestTimeout())
                ->retry(2, 200, throw: false)
                ->withToken($authKey, 'DeepL-Auth-Key')
                ->asForm()
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            throw $this->connectionFailed($e);
        }

        if ($response->failed()) {
            throw $this->upstreamFailed($response, $this->extractErrorMessage($response));
        }

        $body = $response->json();
        $first = $body['translations'][0] ?? null;
        if (! is_array($first) || ! isset($first['text'])) {
            throw $this->missingTranslationsPayload($response->status());
        }

        return new TranslationResult(
            text: (string) $first['text'],
            source_lang: (string) ($first['detected_source_language'] ?? $sourceLang),
            target_lang: $targetLang,
            provider_slug: $this->provider->slug,
            model: null,
            latency_ms: $this->latencyMs($startedAt),
            char_count: mb_strlen($text),
        );
    }

    /**
     * DeepL 使用大写语言代码；中文按通用 ZH 提交，英文目标默认 EN-US。
     */
    private function normalizeDeepLLanguage(string $language, bool $target): string
    {
        $normalized = str_replace('_', '-', trim($language));
        $lower = strtolower($normalized);

        return match ($lower) {
            'zh', 'zh-cn', 'zh-hans', 'zh-tw', 'zh-hant' => 'ZH',
            'en' => $target ? 'EN-US' : 'EN',
            'pt' => $target ? 'PT-BR' : 'PT',
            default => strtoupper($normalized),
        };
    }

    /**
     * 从 DeepL 错误响应体中提取可读错误信息。
     */
    private function extractErrorMessage(Response $response): ?string
    {
        $body = $response->json();
        $message = is_array($body) ? ($body['message'] ?? $body['detail'] ?? null) : null;

        return is_string($message) ? $message : null;
    }
}
