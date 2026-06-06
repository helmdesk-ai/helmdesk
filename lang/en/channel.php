<?php

return [
    'types' => [
        'web' => 'Website',
        'telegram' => 'Telegram',
    ],
    'statuses' => [
        'active' => 'Active',
        'disabled' => 'Inactive',
    ],
    'web_visitor_identity_modes' => [
        'actual_receptionist' => 'Show actual receptionist',
        'unified_service' => 'Show unified service identity',
    ],
    'web_widget_entry_modes' => [
        'bubble' => 'Default bubble',
        'custom' => 'Custom entry',
    ],
    'web_widget_entry_positions' => [
        'right' => 'Right side',
        'left' => 'Left side',
    ],
    'web_widget_entry_styles' => [
        'system' => 'System default',
        'custom' => 'Custom',
    ],
    'web_widget_icon_sizes' => [
        'small' => 'Small (36*36)',
        'medium' => 'Medium (48*48)',
        'large' => 'Large (52*52)',
    ],
    'defaults' => [
        'assistant_name' => 'AI Assistant',
    ],
    'messages' => [
        'created' => 'Channel created.',
        'basic_saved' => 'Basic info saved.',
        'widget_saved' => 'Website embed settings saved.',
        'standalone_saved' => 'Chat link settings saved.',
        'deleted' => 'Channel deleted.',
        'restored' => 'Channel restored.',
        'status_updated' => 'Channel status updated.',
        'active_reception_plan_required' => 'Deploy a reception plan version to this channel first.',
        'active_reception_plan_invalid' => 'The deployed reception plan version is unavailable (archived or the reception agent model is invalid). Update the channel deployment.',
        'invalid_reception_plan_version' => 'Please choose a reception plan version from the current admin.',
        'invalid_reception_plan' => 'Please choose a reception plan from the current admin.',
        'reception_plan_no_usable_version' => 'The selected reception plan has no usable configuration yet. Configure an available reception agent model first.',
        'reception_plan_version_archived' => 'The selected version is archived and cannot be deployed. Pick an active version or publish a new one.',
        'reception_plan_version_model_unavailable' => 'The selected reception plan\'s reception agent model is unavailable. Restore it in AI settings.',
        'invalid_attachment' => 'Image is not available. Please re-upload.',
        'entry_icon_pair_required' => 'Upload the default and selected icons together; leave both empty to use the system default icon.',
    ],
    'query_params' => [
        'locale' => 'Pass a locale hint to the runtime',
        'name' => 'Prefill the visitor name',
        'email' => 'Prefill the visitor email',
        'external_id' => 'Pass an external visitor ID',
        'ref' => 'Pass a reference identifier',
        'utm_source' => 'Pass the UTM source',
        'utm_medium' => 'Pass the UTM medium',
        'utm_campaign' => 'Pass the UTM campaign',
    ],
    'query_param_labels' => [
        'locale' => 'locale',
        'name' => 'name',
        'email' => 'email',
        'external_id' => 'external_id',
        'ref' => 'ref',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
    ],
    'web' => [
        'param_targets' => [
            'contact_name' => 'Contact name',
            'contact_email' => 'Contact email',
            'contact_phone' => 'Contact phone',
            'contact_external_id' => 'Contact external ID',
            'contact_importance' => 'Important contact marker',
            'attribute' => 'Custom attribute',
            'tag' => 'Contact tag',
        ],
        'param_trust' => [
            'signed_only' => 'Signed visitors only',
            'always' => 'Any visitor',
        ],
        'param_write_modes' => [
            'only_if_empty' => 'Write only when empty',
            'overwrite' => 'Overwrite existing value',
        ],
    ],
    'telegram' => [
        'webhook_registered' => 'Telegram webhook registered.',
        'errors' => [
            'invalid_bot_token' => 'This bot token was rejected by Telegram. Double-check the token from @BotFather.',
            'webhook_registration_failed' => 'Could not register the Telegram webhook: :reason',
        ],
    ],
];
