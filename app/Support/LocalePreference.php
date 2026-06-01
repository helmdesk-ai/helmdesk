<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 统一前后端语言偏好的标准化、请求解析和浏览器语言识别。
 */
class LocalePreference
{
    public const DEFAULT_FRONTEND_LOCALE = 'zh-CN';

    public const DEFAULT_LARAVEL_LOCALE = 'zh_CN';

    /**
     * 返回前端当前支持的语言列表。
     *
     * @return list<string>
     */
    public static function frontendLocales(): array
    {
        return [self::DEFAULT_FRONTEND_LOCALE, 'en'];
    }

    /**
     * 将任意语言值标准化为前端语言标识。
     */
    public static function normalizeFrontend(?string $locale): string
    {
        $locale = trim((string) $locale);

        if ($locale === '') {
            return self::DEFAULT_FRONTEND_LOCALE;
        }

        $normalized = str_replace('_', '-', $locale);
        $lower = strtolower($normalized);

        if ($lower === 'en' || str_starts_with($lower, 'en-')) {
            return 'en';
        }

        if ($lower === 'zh' || str_starts_with($lower, 'zh-')) {
            return self::DEFAULT_FRONTEND_LOCALE;
        }

        return self::DEFAULT_FRONTEND_LOCALE;
    }

    /**
     * 将任意语言值标准化为 Laravel 语言目录标识。
     */
    public static function normalizeLaravel(?string $locale): string
    {
        return match (self::normalizeFrontend($locale)) {
            'en' => 'en',
            default => self::DEFAULT_LARAVEL_LOCALE,
        };
    }

    /**
     * 将 Laravel 语言标识转换成前端语言标识。
     */
    public static function frontendFromLaravel(?string $locale): string
    {
        return self::normalizeFrontend(str_replace('_', '-', (string) $locale));
    }

    /**
     * 判断两个 locale 是否可视为同一语言。
     */
    public static function matches(string $first, string $second): bool
    {
        $left = strtolower(str_replace('_', '-', trim($first)));
        $right = strtolower(str_replace('_', '-', trim($second)));

        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        return explode('-', $left)[0] === explode('-', $right)[0];
    }

    /**
     * 从请求参数、Cookie 或浏览器偏好中解析前端语言标识。
     */
    public static function fromRequest(Request $request): string
    {
        return self::normalizeFrontend(
            $request->input('locale')
                ?? $request->cookie('locale')
                ?? self::preferredBrowserLocale($request)
        );
    }

    /**
     * 从 Accept-Language 请求头中选出最接近的受支持语言。
     */
    public static function preferredBrowserLocale(Request $request): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }

        return $request->getPreferredLanguage(self::frontendLocales());
    }
}
