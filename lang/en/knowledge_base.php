<?php

return [
    'categories' => [
        'standard' => 'Standard',
        'qa' => 'Q&A',
        'wechat_public' => 'WeChat Official',
        'helper' => [
            'standard' => 'Upload documents or add custom entries, then index them for retrieval.',
            'qa' => 'Manually curate Q&A pairs — ideal for FAQs and precise matching.',
            'wechat_public' => 'Sync historical articles from a WeChat Official account as knowledge.',
        ],
    ],

    'chunking_strategies' => [
        'fixed' => 'Fixed-size',
        'semantic' => 'Semantic',
    ],

    'groups' => [
        'all_documents' => 'All Documents',
        'default_group' => 'Default Group',
        'created' => 'Group created.',
        'updated' => 'Group saved.',
        'deleted' => 'Group deleted.',
        'name_exists' => 'A group with this name already exists under this knowledge base.',
        'has_children' => 'This group contains sub-groups and cannot be deleted.',
        'has_documents' => 'This group still contains documents. Move them to another group first.',
        'default_locked' => 'The default group cannot be edited, moved, or deleted.',
        'invalid_parent' => 'The selected parent group does not exist or is not a top-level group.',
        'cannot_move_with_children' => 'This group contains sub-groups; please clear them before moving it under another group.',
    ],

    'messages' => [
        'created' => 'Knowledge base created.',
        'updated' => 'Knowledge base saved.',
        'deleted' => 'Knowledge base deleted.',
        'name_exists' => 'A knowledge base with this name already exists.',
        'invalid_attachment' => 'Knowledge base avatar is not available. Please re-upload.',
        'invalid_embedding_model' => 'Please choose an available Embedding model in the current admin.',
        'invalid_embedding_dimension' => 'Please enter the embedding model dimension (an integer between 1 and 65535).',
        'invalid_rerank_model' => 'Please choose an available ReRank model in the current admin.',
        'invalid_summary_model' => 'Please choose an available LLM as the deep index summary model.',
        'model_in_use' => 'This model is used by a knowledge base and cannot be disabled or deleted.',
        'provider_in_use' => 'This provider has models used by knowledge bases and cannot be disabled or deleted.',
        'reindex_dispatched' => 'Reindexing has been triggered.',
    ],

    'knowledge_indexing_strategies' => [
        'text' => 'Text index',
        'vector' => 'Standard index',
        'raptor' => 'Deep index',
        'helper' => [
            'text' => 'Canonical text segments; backing store for full-text search and grep. Always enabled.',
            'vector' => 'Builds the baseline index used for everyday knowledge base answers.',
            'raptor' => 'Builds a deeper layered index for long documents and complex questions.',
        ],
    ],

    'documents' => [
        'uploaded' => 'Document uploaded.',
        'uploaded_n' => 'Uploaded :count document(s).',
        'deleted' => 'Document deleted.',
        'statuses' => [
            'pending' => 'Pending',
            'parsing' => 'Parsing',
            'parsed' => 'Parsed',
            'indexing' => 'Indexing',
            'indexed' => 'Indexed',
            'failed' => 'Failed',
        ],
        'parse_statuses' => [
            'pending' => 'Pending',
            'processing' => 'Parsing',
            'succeeded' => 'Parsed',
            'failed' => 'Parse failed',
            'skipped' => 'Skipped',
        ],
        'indexing_statuses' => [
            'idle' => 'Disabled',
            'pending' => 'Queued',
            'processing' => 'Indexing',
            'succeeded' => 'Ready',
            'failed' => 'Failed',
        ],
        'stages' => [
            'parse' => 'Parse',
            'vector' => 'Standard index',
            'raptor' => 'Deep index',
            'full_text' => 'Full-text index',
        ],
        'source_types' => [
            'upload' => 'Uploaded',
            'manual' => 'Manual',
        ],
        'errors' => [
            'unsupported_extension' => 'Only .md / .markdown / .txt / .pdf / .docx / .html / .htm files are supported.',
            'invalid_group' => 'The selected group does not belong to this knowledge base.',
            'default_group_missing' => 'This knowledge base is missing its default group. Please recreate it.',
            'not_manual_editable' => 'Only manually added documents can be edited inline. Delete and re-upload instead.',
            'not_document_knowledge_base' => 'Q&A knowledge bases cannot contain regular documents.',
            'parse_failed' => 'Failed to parse the document. Please retry or upload a different file.',
            'embedding_failed' => 'Failed to generate embeddings. Please verify the embedding model.',
            'summary_failed' => 'Failed to generate the deep index. Please verify the summary model.',
            'parsed_content_missing' => 'Parsing has not completed yet; cannot start indexing.',
            'no_segments' => 'Parsing produced no segments.',
            'unsupported_strategy' => 'This indexing strategy is not enabled for the knowledge base.',
        ],
    ],

    'qa' => [
        'deleted' => 'Q&A entry deleted.',
        'statuses' => [
            'pending' => 'Pending',
            'indexing' => 'Indexing',
            'indexed' => 'Ready',
            'failed' => 'Failed',
        ],
        'errors' => [
            'not_qa_knowledge_base' => 'Only Q&A knowledge bases can contain Q&A entries.',
            'question_required' => 'Please enter a primary question.',
            'answer_required' => 'Please provide at least one answer.',
        ],
    ],
];
