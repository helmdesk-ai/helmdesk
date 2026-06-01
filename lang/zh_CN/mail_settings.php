<?php

return [
    'drivers' => [
        'smtp' => 'SMTP',
        'sendmail' => 'Sendmail',
        'mailgun' => 'Mailgun',
        'postmark' => 'Postmark',
        'resend' => 'Resend',
        'ses' => 'Amazon SES',
    ],
    'validation' => [
        'required_for_enabled_driver' => '启用当前邮件驱动前，请填写该字段。',
        'secret_required_for_enabled_driver' => '启用当前邮件驱动前，请填写该凭据。',
        'test_requires_enabled' => '请先启用系统邮件。',
        'test_requires_from_address' => '请先填写发件邮箱。',
        'test_requires_complete_settings' => '请先保存完整的邮件配置。',
        'test_send_failed' => '测试邮件发送失败，请检查邮件配置。',
    ],
    'test' => [
        'sent' => '测试邮件已发送',
    ],
];
