<?php

declare(strict_types=1);

return [
    'types' => [
        'text' => 'Text',
        'textarea' => 'Textarea',
        'number' => 'Number',
        'date' => 'Date',
        'boolean' => 'Boolean',
        'single_select' => 'Single Select',
        'multi_select' => 'Multi Select',
    ],
    'sources' => [
        'manual' => 'Manual',
        'api' => 'API',
        'import' => 'Import',
        'workflow' => 'Workflow',
        'ai' => 'AI',
        'merge' => 'Merge',
        'channel' => 'Channel parameter',
    ],
    'reserved_key' => 'The attribute key ":key" is a reserved system field. Please use a different key.',
    'duplicate_key' => 'The attribute key ":key" already exists',
    'invalid_key_format' => 'The attribute key must start with a lowercase letter and may only contain lowercase letters, numbers, and underscores.',
    'invalid_attribute_type' => 'Invalid attribute type',
    'invalid_option_config' => 'Invalid option config: select types must have at least one option',
    'unsupported_filterable_type' => 'Only single select, boolean, date, and number attributes can be used for filtering',
    'invalid_attribute_filter' => 'Invalid attribute filter',
    'attribute_archived' => 'This attribute is archived and its value cannot be modified',
    'invalid_attribute_value' => 'The value for attribute ":name" is invalid',
    'option_code_in_use' => 'Option code ":code" is in use and cannot be removed',
    'option_code_duplicate' => 'Option codes must be unique',
    'invalid_reorder_payload' => 'The submitted attribute order is invalid',
];
