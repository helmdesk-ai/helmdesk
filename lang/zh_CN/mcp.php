<?php

return [
    'page' => [
        'title' => 'MCP 服务',
        'description' => '用 MCP 协议接入外部能力，供不同业务场景调用。',
        'empty' => '还没有添加 MCP 服务。',
        'empty_action' => '添加第一个 MCP 服务',
    ],
    'transports' => [
        'streamable_http' => 'Streamable HTTP',
    ],
    'auth_presets' => [
        'none' => '不认证',
        'bearer' => 'Bearer Token',
        'header' => '自定义请求头',
    ],
    'sync_statuses' => [
        'pending' => '未同步',
        'syncing' => '同步中',
        'success' => '成功',
        'failed' => '失败',
    ],
    'fields' => [
        'name' => '名称',
        'transport' => '传输协议',
        'endpoint_url' => 'Endpoint URL',
        'auth_preset' => '认证方式',
        'bearer_token' => 'Bearer Token',
        'auth_header_name' => '认证 Header 名',
        'auth_header_value' => '认证 Header 值',
        'custom_headers' => '自定义请求头',
        'timeout_seconds' => '超时（秒）',
        'tools_count' => '工具数',
        'last_synced_at' => '最后同步时间',
    ],
    'actions' => [
        'add' => '添加 MCP 服务',
        'save' => '保存',
        'test_connection' => '测试连接',
        'sync_tools' => '重新同步',
        'delete' => '删除',
    ],
    'placeholders' => [
        'keep_credential' => '保留原值（不修改）',
    ],
    'messages' => [
        'created' => 'MCP 服务已创建。',
        'check_succeeded' => '连接正常。',
        'sync_succeeded' => '同步完成，共 :total 个工具（新增 :added、下线 :removed）。',
        'sync_all_queued' => '已开始同步 :count 个 MCP 服务。',
    ],
    'tool' => [
        'removed_badge' => '已下线',
        'description_empty' => '远端未提供描述。',
        'schema_label' => 'Input Schema',
        'annotations_label' => '工具标注',
    ],
    'delete' => [
        'title' => '删除 MCP 服务 ":name"?',
        'description' => '删除后将同时移除已缓存的 :count 个工具记录。',
    ],
    'runtime' => [
        'check' => [
            'succeeded' => 'MCP 服务连通性测试通过。',
            'failed' => 'MCP 服务连接失败：:error',
            'timeout' => 'MCP 服务连接超时。',
            'unauthorized' => 'MCP 服务拒绝认证，请检查凭据。',
            'protocol_error' => 'MCP 协议握手失败：:error',
        ],
        'validate' => [
            'succeeded' => 'MCP 服务配置可用。',
            'missing_endpoint' => '请填写 Endpoint URL。',
            'unsupported_transport' => '暂不支持的 transport：:transport',
        ],
        'list_tools' => [
            'succeeded' => '已拉取工具列表。',
            'failed' => '拉取工具列表失败：:error',
        ],
        'bridge' => [
            'not_configured' => 'MCP 运行时桥未配置，无法完成请求。',
            'unavailable' => 'MCP 运行时不可用：:error',
            'invalid_response' => 'MCP 运行时返回了无效响应。',
            'request_failed' => 'MCP 运行时请求失败。',
        ],
        'request' => [
            'invalid_payload' => '请求格式错误：:error',
        ],
    ],
];
