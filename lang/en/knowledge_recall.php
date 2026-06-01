<?php

return [
    'sources' => [
        'vector' => 'Vector recall',
        'fulltext' => 'Full-text recall',
        'raptor' => 'RAPTOR summary',
    ],
    'fields' => [
        'document' => [
            'parsed_content' => 'Document body',
        ],
        'qa_entry' => [
            'question' => 'Primary question',
            'similar_question' => 'Similar phrasing',
            'answer' => 'Answer',
        ],
    ],
];
