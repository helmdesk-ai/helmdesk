<?php

namespace App\Services\Storage;

use App\Enums\StorageDriver;
use App\Models\StorageProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 校验对象存储 CORS 是否支持浏览器直传。
 */
class StorageCorsChecker
{
    /**
     * 注入对象存储客户端工厂。
     */
    public function __construct(
        private readonly S3ClientFactory $s3ClientFactory,
    ) {}

    /**
     * 解析浏览器请求 origin，缺失时回退到当前站点 scheme+host，
     * 供存储 CORS 检查比对浏览器直传的来源。
     */
    public static function browserOriginFromRequest(Request $request): string
    {
        $origin = $request->headers->get('Origin');

        return is_string($origin) && $origin !== ''
            ? $origin
            : $request->getSchemeAndHttpHost();
    }

    /**
     * 校验存储桶 CORS 是否允许当前站点进行 POST 和 PUT 直传。
     */
    public function assertSupportsBrowserUploads(StorageProfile $profile, string $origin): void
    {
        if ($profile->driver === StorageDriver::Local) {
            return;
        }

        $origin = rtrim($origin, '/');

        try {
            $result = $this->s3ClientFactory->make($profile)->getBucketCors([
                'Bucket' => $profile->bucket,
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'endpoint' => __('storage_settings.cors_check_failed'),
            ]);
        }

        $rules = $result->get('CORSRules') ?? [];
        if (! is_array($rules) || $rules === []) {
            throw ValidationException::withMessages([
                'endpoint' => __('storage_settings.cors_direct_upload_required', ['origin' => $origin]),
            ]);
        }

        if (
            ! $this->allowsPost($rules, $origin)
            || ! $this->allowsPut($rules, $origin)
        ) {
            throw ValidationException::withMessages([
                'endpoint' => __('storage_settings.cors_direct_upload_required', ['origin' => $origin]),
            ]);
        }
    }

    /**
     * 判断 CORS 规则是否允许表单直传。
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function allowsPost(array $rules, string $origin): bool
    {
        return $this->hasMatchingRule($rules, $origin, 'POST', [], []);
    }

    /**
     * 判断 CORS 规则是否允许 PUT 直传并暴露 ETag。
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function allowsPut(array $rules, string $origin): bool
    {
        return $this->hasMatchingRule(
            $rules,
            $origin,
            'PUT',
            ['content-type'],
            ['etag'],
        );
    }

    /**
     * 在 CORS 规则列表中查找满足来源、方法、请求头和响应头的规则。
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  list<string>  $requiredHeaders
     * @param  list<string>  $requiredExposeHeaders
     */
    private function hasMatchingRule(
        array $rules,
        string $origin,
        string $method,
        array $requiredHeaders,
        array $requiredExposeHeaders,
    ): bool {
        foreach ($rules as $rule) {
            if (
                $this->originMatches($this->toStringList($rule['AllowedOrigins'] ?? []), $origin)
                && $this->methodMatches($this->toStringList($rule['AllowedMethods'] ?? []), $method)
                && $this->headersMatch($this->toStringList($rule['AllowedHeaders'] ?? []), $requiredHeaders)
                && $this->exposeHeadersMatch($this->toStringList($rule['ExposeHeaders'] ?? []), $requiredExposeHeaders)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断来源是否命中允许来源，支持通配符域名。
     *
     * @param  list<string>  $allowedOrigins
     */
    private function originMatches(array $allowedOrigins, string $origin): bool
    {
        foreach ($allowedOrigins as $allowedOrigin) {
            $allowedOrigin = rtrim($allowedOrigin, '/');

            if ($allowedOrigin === '*' || $allowedOrigin === $origin) {
                return true;
            }

            if (str_contains($allowedOrigin, '*') && fnmatch($allowedOrigin, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 HTTP 方法是否在 CORS 允许列表中。
     *
     * @param  list<string>  $allowedMethods
     */
    private function methodMatches(array $allowedMethods, string $method): bool
    {
        return in_array(strtoupper($method), array_map('strtoupper', $allowedMethods), true);
    }

    /**
     * 判断上传所需请求头是否全部被 CORS 放行。
     *
     * @param  list<string>  $allowedHeaders
     * @param  list<string>  $requiredHeaders
     */
    private function headersMatch(array $allowedHeaders, array $requiredHeaders): bool
    {
        foreach ($requiredHeaders as $requiredHeader) {
            if (! $this->headerAllowed($allowedHeaders, $requiredHeader)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断前端完成上传所需读取的响应头是否已暴露。
     *
     * @param  list<string>  $exposedHeaders
     * @param  list<string>  $requiredExposeHeaders
     */
    private function exposeHeadersMatch(array $exposedHeaders, array $requiredExposeHeaders): bool
    {
        foreach ($requiredExposeHeaders as $requiredHeader) {
            if (! $this->headerAllowed($exposedHeaders, $requiredHeader)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断单个请求头是否命中允许规则，支持后缀通配。
     *
     * @param  list<string>  $allowedHeaders
     */
    private function headerAllowed(array $allowedHeaders, string $requiredHeader): bool
    {
        $requiredHeader = strtolower($requiredHeader);

        foreach ($allowedHeaders as $allowedHeader) {
            $allowedHeader = strtolower($allowedHeader);

            if ($allowedHeader === '*' || $allowedHeader === $requiredHeader) {
                return true;
            }

            if (str_ends_with($allowedHeader, '*') && str_starts_with($requiredHeader, rtrim($allowedHeader, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 将 SDK 返回的字符串或数组统一为字符串列表。
     *
     * @return list<string>
     */
    private function toStringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
