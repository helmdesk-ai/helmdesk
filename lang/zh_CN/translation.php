<?php

return [
    'protocols' => [
        'google_translate' => 'Google 翻译',
        'deepl' => 'DeepL',
        'azure_translator' => 'Microsoft Azure Translator',
        'baidu_translate' => '百度翻译',
        'tencent_cloud_translate' => '腾讯云机器翻译',
        'amazon_translate' => 'Amazon Translate',
    ],

    'check_succeeded' => '翻译测试成功',
    'cannot_delete_builtin' => '内置供应商不允许删除。',
    'reply_translation_required' => '请先确认发送给访客的译文。',

    'reception_languages' => [
        'zh-CN' => '简体中文',
        'en' => '英语',
        'ja' => '日语',
        'ko' => '韩语',
        'fr' => '法语',
        'de' => '德语',
        'es' => '西班牙语',
        'pt' => '葡萄牙语',
        'it' => '意大利语',
        'ru' => '俄语',
        'ar' => '阿拉伯语',
    ],

    'auto_message_failure_modes' => [
        'skip' => [
            'label' => '不发送这条文案',
            'description' => '翻译不可用时，不向访客发送这条预设文案。',
        ],
        'send_original' => [
            'label' => '发送原文',
            'description' => '翻译不可用时继续向访客发送原文。',
        ],
    ],

    'driver_errors' => [
        'no_default_provider' => '接待方案未配置可用的翻译供应商。',
        'missing_credential' => ':provider 供应商缺少 :field 凭据。',
        'connection_failed' => ':provider 请求失败：:message',
        'upstream_error' => ':provider 返回错误：:message',
        'missing_translations_payload' => ':provider 响应缺少 translations 字段。',
    ],
];
