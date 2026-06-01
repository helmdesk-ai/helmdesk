<?php

declare(strict_types=1);

return [
    'visibilities' => [
        'workspace' => '工作区共享',
        'personal' => '仅自己可见',
    ],
    'token_kinds' => [
        'contact' => '联系人',
        'conversation' => '会话',
        'teammate' => '客服',
        'workspace' => '工作区',
        'ai' => 'AI（即将推出）',
    ],
    'tokens' => [
        'contact_name' => '联系人姓名',
        'contact_email' => '联系人邮箱',
        'contact_primary_phone' => '联系人手机号',
        'conversation_id' => '会话 ID',
        'conversation_subject' => '会话主题',
        'teammate_name' => '当前客服姓名',
        'workspace_name' => '工作区名称',
    ],
    'warnings' => [
        'ai_token_disabled' => 'AI 变量 :token 暂未启用，已保留原文',
        'missing_value' => '变量 :token 在当前会话中没有值，已保留原文',
    ],
    'errors' => [
        'forbidden' => '没有权限管理这个快捷回复',
        'workspace_create_forbidden' => '只有工作区管理员可以创建工作区共享的快捷回复',
        'shortcut_exists' => '同范围下已经有相同短码的快捷回复',
    ],
];
