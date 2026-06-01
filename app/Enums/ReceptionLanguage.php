<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 接待会话面向访客使用的语言。
 */
enum ReceptionLanguage: string implements LabeledEnum
{
    case ChineseSimplified = 'zh-CN';
    case English = 'en';
    case Japanese = 'ja';
    case Korean = 'ko';
    case French = 'fr';
    case German = 'de';
    case Spanish = 'es';
    case Portuguese = 'pt';
    case Italian = 'it';
    case Russian = 'ru';
    case Arabic = 'ar';

    /**
     * 返回接待语言的展示文案。
     */
    public function label(): string
    {
        return match ($this) {
            self::ChineseSimplified => __('translation.reception_languages.zh-CN'),
            self::English => __('translation.reception_languages.en'),
            self::Japanese => __('translation.reception_languages.ja'),
            self::Korean => __('translation.reception_languages.ko'),
            self::French => __('translation.reception_languages.fr'),
            self::German => __('translation.reception_languages.de'),
            self::Spanish => __('translation.reception_languages.es'),
            self::Portuguese => __('translation.reception_languages.pt'),
            self::Italian => __('translation.reception_languages.it'),
            self::Russian => __('translation.reception_languages.ru'),
            self::Arabic => __('translation.reception_languages.ar'),
        };
    }

    /**
     * 返回用于 AI runtime 指令的稳定语言名称。
     */
    public function promptName(): string
    {
        return match ($this) {
            self::ChineseSimplified => 'Simplified Chinese',
            self::English => 'English',
            self::Japanese => 'Japanese',
            self::Korean => 'Korean',
            self::French => 'French',
            self::German => 'German',
            self::Spanish => 'Spanish',
            self::Portuguese => 'Portuguese',
            self::Italian => 'Italian',
            self::Russian => 'Russian',
            self::Arabic => 'Arabic',
        };
    }
}
