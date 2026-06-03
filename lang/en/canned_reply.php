<?php

declare(strict_types=1);

return [
    'visibilities' => [
        'system' => 'System shared',
        'personal' => 'Only me',
    ],
    'token_kinds' => [
        'contact' => 'Contact',
        'conversation' => 'Conversation',
        'teammate' => 'Teammate',
        'system' => 'System',
        'ai' => 'AI (coming soon)',
    ],
    'tokens' => [
        'contact_name' => 'Contact name',
        'contact_email' => 'Contact email',
        'contact_primary_phone' => 'Contact phone',
        'conversation_id' => 'Conversation ID',
        'conversation_subject' => 'Conversation subject',
        'teammate_name' => 'Current teammate',
        'system_name' => 'System name',
    ],
    'warnings' => [
        'ai_token_disabled' => 'AI token :token is not enabled yet; kept as-is.',
        'missing_value' => 'Token :token has no value in this conversation; kept as-is.',
    ],
    'errors' => [
        'forbidden' => 'You do not have permission to manage this canned reply.',
        'system_create_forbidden' => 'Canned reply management permission is required to create system-shared canned replies.',
        'shortcut_exists' => 'A canned reply with the same shortcut already exists in this scope.',
    ],
];
