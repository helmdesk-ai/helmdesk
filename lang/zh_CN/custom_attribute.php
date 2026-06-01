<?php

declare(strict_types=1);

return [
    'types' => [
        'text' => '文本',
        'textarea' => '多行文本',
        'number' => '数字',
        'date' => '日期',
        'boolean' => '布尔',
        'single_select' => '单选',
        'multi_select' => '多选',
    ],
    'sources' => [
        'manual' => '手动',
        'api' => 'API',
        'import' => '导入',
        'workflow' => '自动化',
        'ai' => 'AI',
        'merge' => '合并',
        'channel' => '渠道参数',
    ],
    'reserved_key' => '属性标识「:key」是系统保留字段，请使用其他标识',
    'duplicate_key' => '属性标识「:key」已存在',
    'invalid_key_format' => '属性标识必须以小写字母开头，且只能包含小写字母、数字和下划线',
    'invalid_attribute_type' => '无效的属性类型',
    'invalid_option_config' => '选项配置无效：选择类型必须至少包含一个选项',
    'unsupported_filterable_type' => '仅单选、布尔、日期和数字类型支持筛选',
    'invalid_attribute_filter' => '属性筛选条件无效',
    'attribute_archived' => '该属性已归档，不允许修改值',
    'invalid_attribute_value' => '属性「:name」的值无效',
    'option_code_in_use' => '选项编码「:code」正在被使用，无法删除',
    'option_code_duplicate' => '选项编码不能重复',
    'invalid_reorder_payload' => '提交的属性排序无效',
];
