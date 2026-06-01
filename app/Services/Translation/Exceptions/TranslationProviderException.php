<?php

namespace App\Services\Translation\Exceptions;

use Throwable;

/**
 * Provider 远端返回错误时抛出，保留 HTTP 状态码方便上层做重试 / 降级判断。
 */
class TranslationProviderException extends TranslationException
{
    /**
     * @param  string  $message  对用户可见的错误描述（由 driver 走 __() 本地化）
     * @param  int|null  $statusCode  上游 HTTP 状态码；网络错误时为 null
     * @param  string|null  $providerSlug  抛错的 provider slug，便于日志定位是哪一家
     * @param  Throwable|null  $previous  底层异常（HTTP 响应包装 / ConnectionException 等）
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $providerSlug = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
