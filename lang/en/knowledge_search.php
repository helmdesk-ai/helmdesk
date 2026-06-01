<?php

return [
    'modes' => [
        'grep' => 'Grep-like literal search',
        'semantic' => 'Semantic search',
        'hybrid' => 'Hybrid search',
    ],
    'errors' => [
        'mode_required' => 'Please specify a search mode.',
        'query_required' => 'Please provide at least one query.',
        'knowledge_base_required' => 'Please specify at least one knowledge base.',
        'knowledge_base_inaccessible' => 'The specified knowledge bases are invalid; please check workspace and IDs.',
    ],
];
