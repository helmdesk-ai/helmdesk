<?php

return [
    'modes' => [
        'grep' => '类 grep 字面检索',
        'semantic' => '语义检索',
        'hybrid' => '混合检索',
    ],
    'errors' => [
        'mode_required' => '请指定检索模式。',
        'query_required' => '请提供至少一条检索词或问题。',
        'knowledge_base_required' => '请指定至少一个知识库。',
        'knowledge_base_inaccessible' => '指定的知识库无效，请确认系统与知识库 ID。',
    ],
];
