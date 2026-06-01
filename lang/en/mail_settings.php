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
        'required_for_enabled_driver' => 'This field is required before enabling the selected mail driver.',
        'secret_required_for_enabled_driver' => 'This credential is required before enabling the selected mail driver.',
        'test_requires_enabled' => 'Enable system mail first.',
        'test_requires_from_address' => 'Enter the from address first.',
        'test_requires_complete_settings' => 'Save a complete mail configuration first.',
        'test_send_failed' => 'The test email could not be sent. Please check your mail settings.',
    ],
    'test' => [
        'sent' => 'Test email sent',
    ],
];
