<?php

namespace App\Services\Translation;

use App\Services\Translation\Exceptions\TranslationException;

/**
 * 翻译 driver 统一接口。
 *
 * 所有具体 driver（Google、DeepL、火山、LlmChat 等）都实现这一份契约，调用方只依赖 TranslatorContract，
 * 不感知底层协议；TranslatorManager 按 TranslationProvider 选择具体实现并注入凭据。
 */
interface TranslatorContract
{
    /**
     * 翻译一段文本。
     *
     * @param  string  $text  待翻译原文，不能为空字符串（空字符串调用方应自行短路）
     * @param  string  $sourceLang  源语言 BCP-47 标签，例如 "en"、"zh-CN"；传 "auto" 表示让 provider 自检测
     * @param  string  $targetLang  目标语言 BCP-47 标签
     * @param  array<string, mixed>  $options  driver 特有可选参数（例如 DeepL 的 formality）
     *
     * @throws TranslationException
     */
    public function translate(string $text, string $sourceLang, string $targetLang, array $options = []): TranslationResult;
}
