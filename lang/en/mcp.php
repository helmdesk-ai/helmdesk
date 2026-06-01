<?php

return [
    'page' => [
        'title' => 'MCP Servers',
        'description' => 'Connect external capabilities via MCP for different business workflows.',
        'empty' => 'No MCP servers yet.',
        'empty_action' => 'Add your first MCP server',
    ],
    'transports' => [
        'streamable_http' => 'Streamable HTTP',
    ],
    'auth_presets' => [
        'none' => 'No authentication',
        'bearer' => 'Bearer token',
        'header' => 'Custom header',
    ],
    'sync_statuses' => [
        'pending' => 'Not synced yet',
        'success' => 'Synced',
        'failed' => 'Sync failed',
    ],
    'fields' => [
        'name' => 'Name',
        'transport' => 'Transport',
        'endpoint_url' => 'Endpoint URL',
        'auth_preset' => 'Authentication',
        'bearer_token' => 'Bearer token',
        'auth_header_name' => 'Auth header name',
        'auth_header_value' => 'Auth header value',
        'custom_headers' => 'Custom headers',
        'timeout_seconds' => 'Timeout (seconds)',
        'tools_count' => 'Tools',
        'last_synced_at' => 'Last synced',
    ],
    'actions' => [
        'add' => 'Add MCP server',
        'save' => 'Save',
        'test_connection' => 'Test connection',
        'sync_tools' => 'Re-sync',
        'delete' => 'Delete',
    ],
    'placeholders' => [
        'keep_credential' => 'Keep current value (leave blank)',
    ],
    'messages' => [
        'created' => 'MCP server created.',
        'check_succeeded' => 'Connection is healthy.',
        'sync_succeeded' => 'Synced :total tools (added :added, removed :removed).',
        'cannot_toggle_without_endpoint' => 'Please configure an endpoint URL before enabling.',
        'tool_disabled_due_to_removal' => 'This tool has been removed from the remote server and cannot be enabled.',
    ],
    'tool' => [
        'removed_badge' => 'Removed',
        'description_empty' => 'No description provided by remote.',
        'schema_label' => 'Input schema',
        'annotations_label' => 'Annotations',
    ],
    'delete' => [
        'title' => 'Delete MCP server ":name"?',
        'description' => 'This also removes :count cached tool records.',
    ],
    'runtime' => [
        'check' => [
            'succeeded' => 'MCP connectivity check passed.',
            'failed' => 'MCP connection failed: :error',
            'timeout' => 'MCP connection timed out.',
            'unauthorized' => 'MCP server rejected authentication, please check credentials.',
            'protocol_error' => 'MCP handshake failed: :error',
        ],
        'validate' => [
            'succeeded' => 'MCP server configuration accepted.',
            'missing_endpoint' => 'Endpoint URL is required.',
            'unsupported_transport' => 'Unsupported transport: :transport',
        ],
        'list_tools' => [
            'succeeded' => 'Tool list fetched.',
            'failed' => 'Failed to list tools: :error',
        ],
        'bridge' => [
            'not_configured' => 'MCP runtime bridge is not configured.',
            'unavailable' => 'MCP runtime is unavailable: :error',
            'invalid_response' => 'MCP runtime returned an invalid response.',
            'request_failed' => 'MCP runtime request failed.',
        ],
        'request' => [
            'invalid_payload' => 'Invalid request payload: :error',
        ],
    ],
];
