<?php

namespace App\Services\GoBridge\Exceptions;

use Throwable;

/**
 * Go 桥接不可用异常。
 */
class GoBridgeUnavailableException extends GoBridgeException
{
    /**
     * 构造 Go 桥接不可用异常。
     */
    public function __construct(string $message = 'Go bridge is unavailable.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
