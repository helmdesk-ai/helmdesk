<?php

namespace App\Services\Channel;

use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * 校验访客签名 token。
 *
 * 仅支持 HS256，per-channel 的对称密钥从 ChannelWebSettingsData.user_token_secret 读取。
 *
 * 签名失败/过期/格式不正确时返回 null 并写一条 warn 日志：让客户端继续用 Session 身份接入，
 * 避免轮换密钥或时钟漂移导致整条网站渠道 422。如果业务需要更严格的失败模式，
 * 后续可以扩展 ChannelWebSettingsData 加一个 `strict_user_token` 开关。
 */
class WebChannelUserTokenVerifier
{
    /**
     * JWT 时间字段允许的时钟漂移（秒）。
     */
    private const LEEWAY_SECONDS = 60;

    /**
     * 校验签名 token 并返回标准化身份字段。
     *
     * 返回结构：
     *  - external_id : sub claim，必填
     *  - name        : 可选展示名
     *  - email       : 可选邮箱
     *  - claims      : 原始 payload，便于后续扩展
     *
     * @return array{external_id: string, name: ?string, email: ?string, claims: array<string, mixed>}|null
     */
    public function verify(Channel $channel, ?string $token): ?array
    {
        $settings = $channel->settings instanceof ChannelWebSettingsData
            ? $channel->settings
            : ChannelWebSettingsData::defaults();
        $secret = trim((string) ($settings->user_token_secret ?? ''));
        $token = trim((string) $token);
        if ($token === '' || $secret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return $this->logAndFail('format', $channel);
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $header = $this->decodeJson($headerB64);
        $payload = $this->decodeJson($payloadB64);
        if (! is_array($header) || ! is_array($payload)) {
            return $this->logAndFail('decode', $channel);
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return $this->logAndFail('alg', $channel);
        }
        if (($header['typ'] ?? 'JWT') !== 'JWT') {
            return $this->logAndFail('typ', $channel);
        }

        $expected = hash_hmac('sha256', $headerB64.'.'.$payloadB64, $secret, true);
        $actual = $this->base64UrlDecode($signatureB64);
        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            return $this->logAndFail('signature', $channel);
        }

        $now = time();
        if (isset($payload['exp']) && is_numeric($payload['exp']) && $now > ((int) $payload['exp']) + self::LEEWAY_SECONDS) {
            return $this->logAndFail('expired', $channel);
        }
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && $now + self::LEEWAY_SECONDS < (int) $payload['nbf']) {
            return $this->logAndFail('not_yet_valid', $channel);
        }
        if (isset($payload['iat']) && is_numeric($payload['iat']) && (int) $payload['iat'] > $now + self::LEEWAY_SECONDS) {
            return $this->logAndFail('iat_future', $channel);
        }

        $externalId = $this->stringClaim($payload, 'sub');
        if ($externalId === '') {
            return $this->logAndFail('missing_sub', $channel);
        }
        if (strlen($externalId) > 191) {
            return $this->logAndFail('sub_too_long', $channel);
        }

        $email = $this->stringClaim($payload, 'email');
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        return [
            'external_id' => $externalId,
            'name' => $this->stringClaim($payload, 'name') ?: null,
            'email' => $email !== '' ? strtolower($email) : null,
            'claims' => $payload,
        ];
    }

    /**
     * 为指定渠道生成命名空间，用于 ExternalId identity 隔离不同渠道。
     */
    public function identityNamespace(Channel $channel): string
    {
        return 'web:'.$channel->code;
    }

    private function decodeJson(string $value): mixed
    {
        $decoded = $this->base64UrlDecode($value);
        if (! is_string($decoded)) {
            return null;
        }

        try {
            return json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringClaim(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function logAndFail(string $reason, Channel $channel): ?array
    {
        Log::warning('web channel user_token verification failed', [
            'channel_id' => $channel->id,
            'channel_code' => $channel->code,
            'reason' => $reason,
        ]);

        return null;
    }
}
