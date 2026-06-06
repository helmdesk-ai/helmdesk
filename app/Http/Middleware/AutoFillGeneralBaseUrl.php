<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 首次超级管理员访问时，用当前请求域名自动填充系统主机地址。
 */
class AutoFillGeneralBaseUrl
{
    /**
     * 注入系统基础设置。
     */
    public function __construct(
        private readonly GeneralSettings $settings,
    ) {}

    /**
     * 在共享前端配置前尝试写入实际访问域名。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->fillBaseUrlWhenNeeded($request);

        return $next($request);
    }

    /**
     * 仅在超级管理员访问且配置仍为占位值时自动写入。
     */
    private function fillBaseUrlWhenNeeded(Request $request): void
    {
        if (! $this->isSuperAdminRequest($request)) {
            return;
        }

        $this->settings->refresh();
        $currentBaseUrl = $this->normalizeBaseUrl($this->settings->base_url);
        if (! $this->shouldAutoFill($currentBaseUrl)) {
            return;
        }

        $detectedBaseUrl = $this->detectedBaseUrl($request);
        if ($detectedBaseUrl === null || $detectedBaseUrl === $currentBaseUrl) {
            return;
        }

        $this->settings->base_url = $detectedBaseUrl;
        $this->settings->save();

        config(['app.url' => $detectedBaseUrl]);
    }

    /**
     * 判断当前请求是否来自已登录超级管理员。
     */
    private function isSuperAdminRequest(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && (bool) $user->is_super_admin;
    }

    /**
     * 判断主机地址是否仍可被实际访问域名替换。
     */
    private function shouldAutoFill(?string $baseUrl): bool
    {
        return $baseUrl === null || $baseUrl === GeneralSettings::DEFAULT_BASE_URL;
    }

    /**
     * 从请求中提取实际访问的 scheme 与 host。
     */
    private function detectedBaseUrl(Request $request): ?string
    {
        $scheme = $this->firstForwardedHeader($request, 'x-forwarded-proto') ?? $request->getScheme();
        $host = $this->firstForwardedHeader($request, 'x-forwarded-host') ?? $request->getHttpHost();

        if (! in_array($scheme, ['http', 'https'], true) || trim($host) === '') {
            return null;
        }

        $baseUrl = $this->normalizeBaseUrl($scheme.'://'.$host);

        return is_string($baseUrl) && filter_var($baseUrl, FILTER_VALIDATE_URL) !== false
            ? $baseUrl
            : null;
    }

    /**
     * 读取反向代理转发头中的第一个有效值。
     */
    private function firstForwardedHeader(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim(explode(',', $value)[0]);
    }

    /**
     * 清洗主机地址并移除尾部斜杠。
     */
    private function normalizeBaseUrl(?string $baseUrl): ?string
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return null;
        }

        return rtrim($baseUrl, '/');
    }
}
