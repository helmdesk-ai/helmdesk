<?php

namespace App\Services\GoBridge\Exceptions;

/**
 * Go 桥接返回格式异常。
 */
class GoBridgeInvalidResponseException extends GoBridgeException
{
    /**
     * 记录无法解析的桥接响应状态码。
     */
    public function __construct(public readonly int $httpStatus, string $message = 'Go bridge returned an invalid response.')
    {
        parent::__construct($message);
    }
}
