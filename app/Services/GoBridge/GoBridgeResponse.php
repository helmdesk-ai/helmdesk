<?php

namespace App\Services\GoBridge;

/**
 * Go 桥接的原始响应数据。
 */
final class GoBridgeResponse
{
    /**
     * 保存桥接响应的状态码、成功标记和 JSON body。
     *
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly bool $successful,
        public readonly array $body,
    ) {}
}
