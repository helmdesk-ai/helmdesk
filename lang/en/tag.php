<?php

declare(strict_types=1);

return [
    'sources' => [
        'manual' => 'Manual',
        'system' => 'System',
        'ai' => 'AI',
        'import' => 'Import',
        'channel' => 'Channel parameter',
    ],
    'scopes' => [
        'conversation' => 'Conversation',
        'contact' => 'Contact',
    ],
    'default_groups' => [
        'channel' => 'Channel parameters',
    ],
    'errors' => [
        'name_exists' => 'Tag name already exists',
        'locked_cannot_delete' => 'Locked tags cannot be deleted',
        'locked_cannot_be_merged' => 'Locked tags cannot be merged into another tag',
        'merge_same_tag' => 'A tag cannot be merged into itself',
        'restore_name_conflict' => 'Restore failed: a tag with the same name already exists',
        'group_name_exists' => 'Tag group name already exists',
        'group_scope_mismatch' => 'Tag scope does not match the target tag group',
        'group_not_empty' => 'Tag group still contains tags and cannot be deleted',
        'group_required' => 'Please choose a tag group for the tag',
    ],
    'merge_success' => 'Tags merged successfully',
    'restore_success' => 'Tag restored successfully',
];
