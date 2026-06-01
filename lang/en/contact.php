<?php

declare(strict_types=1);

return [
    'types' => [
        'visitor' => 'Visitor',
        'contact' => 'Contact',
    ],
    'list_types' => [
        'all' => 'All',
        'contacts' => 'Contacts',
        'visitors' => 'Visitors',
    ],
    'sources' => [
        'web' => 'Web',
        'email' => 'Email',
        'api' => 'API',
        'manual' => 'Manual',
        'telegram' => 'Telegram',
    ],
    'tag_match_modes' => [
        'any' => 'Any',
        'all' => 'All',
    ],
    'identity_types' => [
        'session' => 'Session',
        'email' => 'Email',
        'phone' => 'Phone',
        'external_id' => 'External ID',
    ],
    'anonymous_visitor' => 'Anonymous visitor',
    'anonymous_visitor_with_suffix' => 'Anonymous visitor #:suffix',
    'identity_already_exists' => 'This :type is already associated with contact ":name"',
    'at_least_one_identity' => 'At least one identity (email or phone) is required',
    'invalid_phone' => 'Please enter a valid phone number',
    'invalid_email' => 'Please enter a valid email address',
    'invalid_ai_context' => 'The AI context payload is invalid',
    'ai_context_too_large' => 'The AI context is too large. Please compress it before saving',
    'identity_manual_management_not_supported' => 'This identity cannot be edited or deleted manually',
    'restore_conflict' => 'Cannot restore: :type ":value" is already used by contact ":name"',
    'namespace_required_for_external_id' => 'Namespace is required for external ID type',
];
