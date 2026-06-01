<?php

return [
    'categories' => [
        'standard' => '普通知识库',
        'qa' => '问答知识库',
        'wechat_public' => '公众号知识库',
        'helper' => [
            'standard' => '支持上传文档和手动添加自定义内容，解析后用于知识库检索。',
            'qa' => '手动录入问答对，适合 FAQ 等精匹配场景。',
            'wechat_public' => '同步公众号历史文章作为知识来源。',
        ],
    ],

    'chunking_strategies' => [
        'fixed' => '普通分段',
        'semantic' => '语义分段',
    ],

    'groups' => [
        'all_documents' => '全部文档',
        'default_group' => '默认分组',
        'created' => '分组已创建。',
        'updated' => '分组已保存。',
        'deleted' => '分组已删除。',
        'name_exists' => '当前知识库下已存在同名分组。',
        'has_children' => '该分组包含子分组，请先删除子分组。',
        'has_documents' => '该分组下还有文档，请先将文档移动到其它分组。',
        'default_locked' => '默认分组不能编辑、移动或删除。',
        'invalid_parent' => '上级分组不存在或不是顶级分组。',
        'cannot_move_with_children' => '该分组包含子分组，请先清空子分组再移动。',
    ],

    'messages' => [
        'created' => '知识库已创建。',
        'updated' => '知识库已保存。',
        'deleted' => '知识库已删除。',
        'name_exists' => '同名知识库已存在。',
        'invalid_attachment' => '知识库头像不可用，请重新上传。',
        'invalid_embedding_model' => '请选择当前工作区中可用的嵌入模型。',
        'invalid_embedding_dimension' => '请填写嵌入模型的向量维度（1-65535 的整数）。',
        'invalid_rerank_model' => '请选择当前工作区中可用的重排序模型。',
        'invalid_summary_model' => '请选择当前工作区中可用的大语言模型作为深度索引摘要模型。',
        'model_in_use' => '该模型已被知识库使用，不能停用或删除。',
        'provider_in_use' => '该供应商已有模型被知识库使用，不能停用或删除。',
        'reindex_dispatched' => '已重新触发索引。',
    ],

    'knowledge_indexing_strategies' => [
        'text' => '文本索引',
        'vector' => '标准索引',
        'raptor' => '深度索引',
        'helper' => [
            'text' => '解析后的文本分段，是全文检索与 grep 的存储载体，始终启用。',
            'vector' => '为文档建立基础索引，用于日常知识库问答。',
            'raptor' => '为长文档建立更深入的层级索引，提升复杂问题的命中效果。',
        ],
    ],

    'documents' => [
        'uploaded' => '文档已上传。',
        'uploaded_n' => '已上传 :count 个文档。',
        'deleted' => '文档已删除。',
        'statuses' => [
            'pending' => '待处理',
            'parsing' => '解析中',
            'parsed' => '已解析',
            'indexing' => '索引中',
            'indexed' => '已就绪',
            'failed' => '已失败',
        ],
        'parse_statuses' => [
            'pending' => '待解析',
            'processing' => '解析中',
            'succeeded' => '已解析',
            'failed' => '解析失败',
            'skipped' => '已跳过',
        ],
        'indexing_statuses' => [
            'idle' => '未启用',
            'pending' => '排队中',
            'processing' => '索引中',
            'succeeded' => '已就绪',
            'failed' => '索引失败',
        ],
        'stages' => [
            'parse' => '解析',
            'vector' => '标准索引',
            'raptor' => '深度索引',
            'full_text' => '全文索引',
        ],
        'source_types' => [
            'upload' => '上传',
            'manual' => '手动',
        ],
        'errors' => [
            'unsupported_extension' => '当前仅支持 .md / .markdown / .txt / .pdf / .docx / .html / .htm 格式的文档。',
            'invalid_group' => '所选分组不属于当前知识库。',
            'default_group_missing' => '当前知识库缺少默认分组，请重新创建知识库。',
            'not_manual_editable' => '只有手动添加的文档才能在线编辑，请删除后重新上传。',
            'not_document_knowledge_base' => '问答知识库不能添加普通文档。',
            'parse_failed' => '文档解析失败，请稍后重试或更换文件。',
            'embedding_failed' => '标准索引生成失败，请检查嵌入模型可用性。',
            'summary_failed' => '深度索引生成失败，请检查摘要模型可用性。',
            'parsed_content_missing' => '尚未完成解析，无法触发索引。',
            'no_segments' => '解析结果为空，未能产出任何语义段。',
            'unsupported_strategy' => '当前知识库未启用该索引策略。',
        ],
    ],

    'qa' => [
        'deleted' => '问答已删除。',
        'statuses' => [
            'pending' => '待处理',
            'indexing' => '索引中',
            'indexed' => '已就绪',
            'failed' => '已失败',
        ],
        'errors' => [
            'not_qa_knowledge_base' => '只有问答知识库才能添加问答。',
            'question_required' => '请填写标准问题。',
            'answer_required' => '请至少填写一个答案。',
        ],
    ],
];
