<?php

return [
    'protocols' => [
        'google_translate' => 'Google Translate',
        'deepl' => 'DeepL',
        'azure_translator' => 'Microsoft Azure Translator',
        'baidu_translate' => 'Baidu Translate',
        'tencent_cloud_translate' => 'Tencent Cloud Machine Translation',
        'amazon_translate' => 'Amazon Translate',
    ],

    'check_succeeded' => 'Translation test succeeded.',
    'cannot_delete_builtin' => 'Built-in providers cannot be deleted.',
    'reply_translation_required' => 'Confirm the visitor-facing translation before sending.',

    'reception_languages' => [
        'zh-CN' => 'Simplified Chinese',
        'en' => 'English',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'fr' => 'French',
        'de' => 'German',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'it' => 'Italian',
        'ru' => 'Russian',
        'ar' => 'Arabic',
    ],

    'auto_message_failure_modes' => [
        'skip' => [
            'label' => 'Do not send this message',
            'description' => 'Do not send this preset message to the visitor when translation is unavailable.',
        ],
        'send_original' => [
            'label' => 'Send original text',
            'description' => 'Send the original preset text to the visitor when translation is unavailable.',
        ],
    ],

    'driver_errors' => [
        'no_default_provider' => 'The reception plan has no usable translation provider configured.',
        'missing_credential' => ':provider provider is missing :field credential.',
        'connection_failed' => ':provider request failed: :message',
        'upstream_error' => ':provider returned an error: :message',
        'missing_translations_payload' => ':provider response is missing translations payload.',
    ],
];
