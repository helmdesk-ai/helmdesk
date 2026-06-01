<?php

return [
    'bridge' => [
        'not_configured' => 'Knowledge runtime bridge is not configured. Please check services.go_runtime.base_url.',
        'unavailable' => 'Knowledge runtime bridge is currently unavailable. Please retry later.',
        'invalid_response' => 'Knowledge runtime bridge returned an unparseable response.',
        'request_failed' => 'Knowledge runtime bridge request failed.',
    ],
    'embed' => [
        'succeeded' => 'Index content generation succeeded.',
        'model_unavailable' => 'Embedding model is currently unavailable.',
        'failed' => 'Index content generation failed.',
    ],
    'summarize' => [
        'succeeded' => 'Summary generation succeeded.',
        'model_unavailable' => 'Summary model is currently unavailable.',
        'failed' => 'Summary generation failed.',
    ],
    'rerank' => [
        'succeeded' => 'Rerank completed.',
        'model_unavailable' => 'Rerank model is currently unavailable; skipping reranking.',
        'failed' => 'Rerank request failed; skipping reranking.',
    ],
];
