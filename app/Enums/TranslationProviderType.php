<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 翻译供应商协议，决定凭据字段、调用方式以及对应的 driver 实现。
 *
 * 与 AiProviderProtocol 拆开是因为：翻译供应商既有 LLM 也有传统 MT API，配置维度（术语表、formality、
 * 区域变体）和 chat completion 完全不同，混用会让设置页和 driver 抽象都很拧巴。
 */
enum TranslationProviderType: string implements LabeledEnum
{
    case GoogleTranslate = 'google-translate';
    case DeepL = 'deepl';
    case AzureTranslator = 'azure-translator';
    case BaiduTranslate = 'baidu-translate';
    case TencentCloudTranslate = 'tencent-cloud-translate';
    case AmazonTranslate = 'amazon-translate';

    /**
     * 返回设置页和选项下拉里展示的本地化名称。
     */
    public function label(): string
    {
        return match ($this) {
            self::GoogleTranslate => __('translation.protocols.google_translate'),
            self::DeepL => __('translation.protocols.deepl'),
            self::AzureTranslator => __('translation.protocols.azure_translator'),
            self::BaiduTranslate => __('translation.protocols.baidu_translate'),
            self::TencentCloudTranslate => __('translation.protocols.tencent_cloud_translate'),
            self::AmazonTranslate => __('translation.protocols.amazon_translate'),
        };
    }
}
