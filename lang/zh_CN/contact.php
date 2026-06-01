<?php

declare(strict_types=1);

return [
    'types' => [
        'visitor' => '访客',
        'contact' => '联系人',
    ],
    'list_types' => [
        'all' => '全部',
        'contacts' => '联系人',
        'visitors' => '访客',
    ],
    'sources' => [
        'web' => '网站',
        'email' => '邮件',
        'api' => 'API',
        'manual' => '手动创建',
        'telegram' => 'Telegram',
    ],
    'tag_match_modes' => [
        'any' => '任意一个',
        'all' => '全部',
    ],
    'identity_types' => [
        'session' => '会话',
        'email' => '邮箱',
        'phone' => '手机号',
        'external_id' => '外部 ID',
    ],
    'anonymous_visitor' => '匿名访客',
    'anonymous_visitor_with_suffix' => '匿名访客 #:suffix',
    'identity_already_exists' => '该:type已关联到联系人「:name」',
    'at_least_one_identity' => '至少需要提供一个身份标识（邮箱或手机号）',
    'invalid_phone' => '请输入有效的手机号',
    'invalid_email' => '请输入有效的邮箱地址',
    'invalid_ai_context' => 'AI 画像数据格式无效',
    'ai_context_too_large' => 'AI 画像内容过大，请先压缩后再保存',
    'identity_manual_management_not_supported' => '该身份标识暂不支持手动修改或删除',
    'restore_conflict' => '无法恢复：:type「:value」已被联系人「:name」使用',
    'namespace_required_for_external_id' => '外部 ID 类型必须指定命名空间',
];
