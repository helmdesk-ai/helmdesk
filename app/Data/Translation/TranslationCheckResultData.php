<?php

namespace App\Data\Translation;

use App\Services\Translation\TranslationResult;
use Spatie\LaravelData\Data;

/**
 * 翻译连通测试结果。
 *
 * 由 CheckTranslationProviderAction 返回给前端的「测试」按钮处理；
 * 走 useHttp 的 JSON 通道，前端按 success 字段判断分支：true 时读 result 字段展示译文，
 * false 时把 message 文案直接弹 toast。
 */
class TranslationCheckResultData extends Data
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?TranslationResult $result = null,
    ) {}
}
