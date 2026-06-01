<?php

return [
    'sources' => [
        'vector' => '向量召回',
        'fulltext' => '全文召回',
        'raptor' => 'RAPTOR 摘要',
    ],
    'fields' => [
        'document' => [
            'parsed_content' => '文档正文',
        ],
        'qa_entry' => [
            'question' => '主问题',
            'similar_question' => '相似问法',
            'answer' => '答案',
        ],
    ],
];
