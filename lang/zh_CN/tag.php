<?php

declare(strict_types=1);

return [
    'sources' => [
        'manual' => '手动',
        'system' => '系统',
        'ai' => 'AI',
        'import' => '导入',
        'channel' => '渠道参数',
    ],
    'scopes' => [
        'conversation' => '会话',
        'contact' => '联系人',
    ],
    'default_groups' => [
        'channel' => '渠道参数',
    ],
    'errors' => [
        'name_exists' => '标签名已存在',
        'locked_cannot_delete' => '锁定的标签不能删除',
        'locked_cannot_be_merged' => '锁定的标签不能被合并到其他标签',
        'merge_same_tag' => '标签不能合并到自己',
        'restore_name_conflict' => '恢复失败：已存在同名标签',
        'group_name_exists' => '标签组名已存在',
        'group_scope_mismatch' => '标签与目标标签组的适用维度不一致',
        'group_not_empty' => '标签组下仍有标签，无法删除',
        'group_required' => '请选择标签所属的标签组',
    ],
    'merge_success' => '标签合并成功',
    'restore_success' => '标签恢复成功',
];
