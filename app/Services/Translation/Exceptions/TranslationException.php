<?php

namespace App\Services\Translation\Exceptions;

use RuntimeException;

/**
 * 翻译领域基础异常，所有翻译相关异常都继承自此类，调用方可以一把抓。
 */
class TranslationException extends RuntimeException {}
