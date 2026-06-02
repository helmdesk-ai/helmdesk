<?php

namespace App\Data\Translation;

use Spatie\LaravelData\Data;

/**
 * 测试翻译供应商连通的表单数据。
 *
 * 来自 resources/js/pages/systemSettings/translationProviders/Index.vue 的「测试」按钮，
 * 用户可以填一段原文 + 目标语言验证当前已保存的凭据是否能正常发起翻译请求。
 * 默认值由前端提供（"Hello" / "zh-CN"），后端只做长度校验，不做语种白名单（不同 driver 支持范围不一）。
 */
class FormCheckTranslationProviderData extends Data
{
    public function __construct(
        public string $text,
        public string $target_lang,
        public ?string $source_lang = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:500'],
            'target_lang' => ['required', 'string', 'max:16'],
            'source_lang' => ['nullable', 'string', 'max:16'],
        ];
    }
}
