<?php

declare(strict_types=1);

return [
    'visibilities' => [
        'workspace' => 'Workspace shared',
        'personal' => 'Only me',
    ],
    'token_kinds' => [
        'contact' => 'Contact',
        'conversation' => 'Conversation',
        'teammate' => 'Teammate',
        'workspace' => 'Workspace',
        'ai' => 'AI (coming soon)',
    ],
    'tokens' => [
        'contact_name' => 'Contact name',
        'contact_email' => 'Contact email',
        'contact_primary_phone' => 'Contact phone',
        'conversation_id' => 'Conversation ID',
        'conversation_subject' => 'Conversation subject',
        'teammate_name' => 'Current teammate',
        'workspace_name' => 'Workspace name',
    ],
    'warnings' => [
        'ai_token_disabled' => 'AI token :token is not enabled yet; kept as-is.',
        'missing_value' => 'Token :token has no value in this conversation; kept as-is.',
    ],
    'errors' => [
        'forbidden' => 'You do not have permission to manage this canned reply.',
        'workspace_create_forbidden' => 'Only workspace administrators can create workspace-shared canned replies.',
        'shortcut_exists' => 'A canned reply with the same shortcut already exists in this scope.',
    ],
];
