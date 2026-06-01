<?php

namespace App\Services\GoBridge\Exceptions;

/**
 * Go 桥接未配置异常。
 */
class GoBridgeNotConfiguredException extends GoBridgeException
{
    /**
     * 构造 Go 桥接未配置异常。
     */
    public function __construct(string $message = 'Go bridge base URL is not configured.')
    {
        parent::__construct($message);
    }
}
